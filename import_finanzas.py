import os
from pathlib import Path
from typing import Optional

import pandas as pd
import numpy as np
import mysql.connector as mysql

# ==========================
# Configuración general DB y parámetros
# ==========================
DB_HOST = os.environ.get('DB_HOST', '127.0.0.1')
DB_USER = os.environ.get('DB_USER', 'root')
DB_PASS = os.environ.get('DB_PASS', '')
DB_NAME = os.environ.get('DB_NAME', 'hermes_express')

# Parámetros de negocio (ajustables por entorno)
SALDO_INICIAL = float(os.environ.get('SALDO_INICIAL', '0'))
SALDO_UMBRAL = float(os.environ.get('SALDO_UMBRAL', '100'))

# Rutas de archivos Excel esperados
BASE_DIR = Path(__file__).parent
EXCEL_CAJA = BASE_DIR / 'caja chica.xlsx'
EXCEL_RESUMEN_IE = BASE_DIR / 'INGRESOS Y EGRESOS.xlsx'
EXCEL_HERMES = BASE_DIR / 'RESUMEN HERMES.xlsx'


# ==========================
# Utilidades
# ==========================
def connect_db():
    return mysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        autocommit=False,
    )


def ensure_tables(cursor):
    # Tabla: caja_chica_movimientos
    cursor.execute(
        """
        CREATE TABLE IF NOT EXISTS caja_chica_movimientos (
            id INT NOT NULL AUTO_INCREMENT,
            fecha DATE NOT NULL,
            categoria VARCHAR(255) NOT NULL,
            descripcion TEXT NOT NULL,
            monto DECIMAL(15,2) NOT NULL,
            tipo ENUM('ingreso','egreso') NOT NULL,
            saldo_actual DECIMAL(15,2) NULL,
            alerta_saldo_bajo TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_fecha (fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        """
    )

    # Tabla: resumen_ing_egr
    cursor.execute(
        """
        CREATE TABLE IF NOT EXISTS resumen_ing_egr (
            id INT NOT NULL AUTO_INCREMENT,
            mes CHAR(7) NOT NULL,
            total_ingresos DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_egresos DECIMAL(15,2) NOT NULL DEFAULT 0,
            resultado_neto DECIMAL(15,2) NOT NULL DEFAULT 0,
            alerta_resultado_negativo TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_mes (mes)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        """
    )

    # Tabla: resumen_hermes
    cursor.execute(
        """
        CREATE TABLE IF NOT EXISTS resumen_hermes (
            id INT NOT NULL AUTO_INCREMENT,
            mes CHAR(7) NOT NULL,
            resultado_neto DECIMAL(15,2) NOT NULL,
            variacion_pct DECIMAL(9,4) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_mes (mes)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        """
    )


# ==========================
# (1) Lectura de Excel y normalización de columnas
# ==========================
def _norm(s: str) -> str:
    s = str(s or '').lower()
    # quitar espacios y caracteres no alfanuméricos
    return ''.join(ch for ch in s if ch.isalnum())

def resolve_sheet(path: Path, prefer: list[str]) -> str:
    """Devuelve el nombre de hoja a usar. Intenta coincidir preferidos; si no, heurística por contenido; si no, la primera."""
    xls = pd.ExcelFile(path)
    sheets = list(xls.sheet_names)
    if not sheets:
        raise ValueError(f'El archivo {path} no contiene hojas')
    pnorm = [_norm(p) for p in prefer]
    # match exact normalizado
    for sh in sheets:
        if _norm(sh) in pnorm:
            return sh
    # heurísticas comunes
    heuristics = {
        'mov': ['movimiento', 'movimientos', 'movs', 'caja', 'cajachica'],
        'resumen': ['resumen', 'summary', 'ie', 'ingresosegresos'],
        'informe': ['informe', 'report', 'hermes']
    }
    for sh in sheets:
        n = _norm(sh)
        if any(k in pnorm for k in ['movimiento','movimientos']):
            if any(tok in n for tok in heuristics['mov']):
                return sh
        if any(k in pnorm for k in ['resumen']):
            if any(tok in n for tok in heuristics['resumen']):
                return sh
        if any(k in pnorm for k in ['informe']):
            if any(tok in n for tok in heuristics['informe']):
                return sh
    # última opción: primera hoja
    return sheets[0]

def sheet_has_unnamed_columns(df: pd.DataFrame) -> bool:
    cols = [str(c).lower() for c in df.columns]
    return all(c.startswith('unnamed') for c in cols)

def try_promote_header_from_row(path: Path, sheet: str | int) -> pd.DataFrame:
    """Relee la hoja sin encabezado y usa una fila con meses como encabezados."""
    raw = pd.read_excel(path, sheet_name=sheet, header=None)
    # buscar fila con nombres de meses o fechas
    month_keys = ['ene','enero','feb','febrero','mar','marzo','abr','abril','may','mayo','jun','junio','jul','julio','ago','agosto','sep','sept','septiembre','oct','octubre','nov','noviembre','dic','diciembre']
    header_idx = None
    for i in range(min(len(raw), 30)):
        row_vals = [str(v).strip().lower() for v in raw.iloc[i].tolist()]
        # si hay al menos 2 celdas que parezcan meses o varias celdas fecha/año-mes
        month_like = sum(1 for v in row_vals if any(k in v.replace('.', ' ').replace('-', ' ').replace('/', ' ') for k in month_keys))
        ymd_like = 0
        for v in row_vals:
            dt = pd.to_datetime(v, errors='coerce', dayfirst=True)
            if pd.notna(dt):
                ymd_like += 1
        if month_like >= 2 or ymd_like >= 2 or ('mes' in row_vals):
            header_idx = i
            break
    if header_idx is None:
        # usar primera fila como encabezado por defecto
        header_idx = 0
    # promover encabezado
    promoted = raw.iloc[header_idx+1:].copy()
    promoted.columns = [str(c).strip().lower() for c in raw.iloc[header_idx].tolist()]
    # eliminar columnas totalmente vacías
    promoted = promoted.dropna(axis=1, how='all')
    return promoted

def _label_col_index(df: pd.DataFrame) -> int:
    """Retorna el índice de la columna 'etiqueta' (más no numérica). Evita error con columnas duplicadas."""
    best_idx = 0
    best_score = -1
    for i in range(df.shape[1]):
        s = df.iloc[:, i]
        # score: cantidad de celdas no numéricas
        try:
            numeric = pd.to_numeric(s, errors='coerce')
            score = numeric.isna().sum()
        except Exception:
            score = len(s)
        if score > best_score:
            best_score = score
            best_idx = i
    return best_idx

def read_caja_chica(path: Path, sheet_name: str = 'Movimientos') -> pd.DataFrame:
    # (1) lectura: Excel de Caja Chica
    try:
        df = pd.read_excel(path, sheet_name=sheet_name)
    except Exception:
        # Resolver hoja automáticamente si la indicada no existe
        sh = resolve_sheet(path, [sheet_name])
        df = pd.read_excel(path, sheet_name=sh)
    # Normalizar nombres de columnas
    if sheet_has_unnamed_columns(df):
        # intentar promover encabezado desde una fila interna
        try:
            df = try_promote_header_from_row(path, sheet_name)
        except Exception:
            pass
    df.columns = [str(c).strip().lower() for c in df.columns]

    def find_col_exact_or_contains(candidates: list[str], contains_tokens: list[str]) -> str | None:
        for c in df.columns:
            if c in candidates:
                return c
        for c in df.columns:
            n = str(c).lower()
            if any(tok in n for tok in contains_tokens):
                return c
        return None

    # Intentar detectar columnas requeridas de forma tolerante
    col_fecha = find_col_exact_or_contains(['fecha', 'fechas', 'date'], ['fecha', 'date'])
    col_categoria = find_col_exact_or_contains(['categoria', 'categoría', 'category', 'rubro', 'clasificacion'], ['categ', 'rubro', 'clasif'])
    col_desc = find_col_exact_or_contains(['descripcion', 'descripción', 'detalle', 'concepto', 'glosa', 'description', 'observacion'], ['desc', 'detall', 'concept', 'glosa', 'observ'])
    col_monto = find_col_exact_or_contains(['monto', 'importe', 'amount', 'valor', 'total'], ['monto', 'importe', 'amount', 'valor', 'total'])
    col_tipo = find_col_exact_or_contains(['tipo', 'type', 'movimiento'], ['tipo', 'movim'])

    # Soporte a columnas separadas de ingreso/egreso
    col_ing = find_col_exact_or_contains(['ingreso', 'ingresos'], ['ingres'])
    col_egr = find_col_exact_or_contains(['egreso', 'egresos'], ['egres'])
    # Contabilidad clásica: haber/abono/credito ~ ingreso, debe/cargo/debito ~ egreso
    col_haber = find_col_exact_or_contains(['haber', 'abono', 'credito', 'crédito', 'entrada'], ['haber','abono','credit','crédit','entrada'])
    col_debe  = find_col_exact_or_contains(['debe', 'cargo', 'debito', 'débito', 'salida'], ['debe','cargo','debit','déb','salida'])

    # Si no hay descripcion, intentar usar categoría como descripción
    if col_desc is None:
        col_desc = col_categoria
    # Si no hay categoría, crear vacía
    if col_categoria is None:
        df['__categoria'] = ''
        col_categoria = '__categoria'

    # Componer DataFrame base
    fecha_series = pd.to_datetime(df[col_fecha], errors='coerce').dt.date if col_fecha else pd.NaT
    categoria_series = df[col_categoria].astype(str).fillna('') if col_categoria else ''
    descripcion_series = df[col_desc].astype(str).fillna('') if col_desc else ''

    if col_monto is not None:
        montos = pd.to_numeric(df[col_monto], errors='coerce')
        tipos_series = None
        if col_tipo is not None:
            tipos_series = df[col_tipo].astype(str).str.strip().str.lower().replace({'ingresos':'ingreso','egresos':'egreso'})
        else:
            tipos_series = np.where(montos >= 0, 'ingreso', 'egreso')
    elif col_ing is not None or col_egr is not None:
        ingresos = pd.to_numeric(df[col_ing], errors='coerce') if col_ing else 0
        egresos = pd.to_numeric(df[col_egr], errors='coerce') if col_egr else 0
        # Si ambas existen y en una fila hay ambos, priorizar el signo neto
        montos = (pd.to_numeric(ingresos, errors='coerce').fillna(0) - pd.to_numeric(egresos, errors='coerce').fillna(0))
        tipos_series = np.where(montos < 0, 'egreso', 'ingreso')
    elif col_haber is not None or col_debe is not None:
        haber = pd.to_numeric(df[col_haber], errors='coerce') if col_haber else 0
        debe  = pd.to_numeric(df[col_debe],  errors='coerce') if col_debe  else 0
        montos = (pd.to_numeric(haber, errors='coerce').fillna(0) - pd.to_numeric(debe, errors='coerce').fillna(0))
        tipos_series = np.where(montos < 0, 'egreso', 'ingreso')
    else:
        # Último intento: escoger la primera columna numérica como monto
        num_cols = [c for c in df.columns if pd.api.types.is_numeric_dtype(df[c]) or pd.to_numeric(df[c], errors='coerce').notna().any()]
        if num_cols:
            sel = num_cols[0]
            montos = pd.to_numeric(df[sel], errors='coerce')
            tipos_series = np.where(montos < 0, 'egreso', 'ingreso')
        else:
            cols = ', '.join(df.columns)
            raise ValueError(f'Hoja Movimientos no contiene columnas de monto/ingreso/egreso reconocibles. Columnas encontradas: {cols}')

    out = pd.DataFrame({
        'fecha': fecha_series,
        'categoria': categoria_series,
        'descripcion': descripcion_series,
        'monto': montos
    })

    # Normalizar tipo y monto
    out['tipo'] = tipos_series
    out['monto'] = pd.to_numeric(out['monto'], errors='coerce').fillna(0)
    out['tipo'] = out['tipo'].where(out['tipo'].isin(['ingreso','egreso']), np.where(out['monto']>=0,'ingreso','egreso'))
    out['monto'] = np.where(out['tipo'] == 'egreso', -abs(out['monto']), abs(out['monto']))

    # Limpiar filas inválidas
    out = out.dropna(subset=['fecha'])
    out = out.sort_values(['fecha']).reset_index(drop=True)
    return out


def read_ingresos_egresos(path: Path, sheet_name: str = 'Resumen') -> pd.DataFrame:
    # (1) lectura: Excel de Ingresos y Egresos (Resumen)
    try:
        used_sheet = sheet_name
        df = pd.read_excel(path, sheet_name=used_sheet)
    except Exception:
        prefer = [sheet_name, 'INGRESOS Y GASTOS CHICLAYO', 'TABLA DIAMICA CHICLAYO', 'TABLA DINAMICA CHICLAYO']
        used_sheet = resolve_sheet(path, prefer)
        df = pd.read_excel(path, sheet_name=used_sheet)
    if sheet_has_unnamed_columns(df):
        # intentar promover encabezado desde una fila interna
        try:
            df = try_promote_header_from_row(path, used_sheet)
        except Exception:
            pass
    df.columns = [str(c).strip().lower() for c in df.columns]

    # Intentar detectar un esquema transaccional: fecha/tipo/monto
    col_fecha = next((c for c in df.columns if c in ['fecha', 'fechas', 'date', 'mes']), None)
    col_monto_ing = next((c for c in df.columns if c in ['ingreso', 'ingresos', 'total_ingresos', 'monto_ingreso']), None)
    col_monto_egr = next((c for c in df.columns if c in ['egreso', 'egresos', 'total_egresos', 'monto_egreso']), None)
    col_tipo = next((c for c in df.columns if c in ['tipo', 'type', 'movimiento']), None)
    col_monto = next((c for c in df.columns if c in ['monto', 'importe', 'amount', 'valor']), None)

    if col_monto_ing or col_monto_egr:
        # Ya vienen separados ingresos/egresos por fila/mes
        # Si no tenemos columna de fecha/mes, intentar detectarla por contenido
        if col_fecha is None:
            candidate = None
            max_nonnull = 0
            for c in df.columns:
                try:
                    s = pd.to_datetime(df[c], errors='coerce')
                    cnt = s.notna().sum()
                    if cnt > max_nonnull and cnt > 0:
                        max_nonnull = cnt
                        candidate = c
                except Exception:
                    pass
            col_fecha = candidate
            if col_fecha is None:
                # No pudimos detectar; dejar que el flujo crosstab maneje el formato
                col_monto_ing = None
                col_monto_egr = None
        if col_monto_ing is not None or col_monto_egr is not None:
            series_mes = pd.to_datetime(df[col_fecha], errors='coerce')
        # si es mensual, el día puede ser 1; formatear a YYYY-MM
            mes = series_mes.dt.to_period('M').astype(str)
            ingresos = pd.to_numeric(df[col_monto_ing], errors='coerce') if col_monto_ing else 0
            egresos = pd.to_numeric(df[col_monto_egr], errors='coerce') if col_monto_egr else 0
            tmp = pd.DataFrame({'mes': mes, 'ingresos': ingresos, 'egresos': egresos})
            tmp = tmp.groupby('mes', as_index=False).sum(numeric_only=True)
    elif col_fecha is not None and (col_tipo is not None or col_monto is not None):
        # Datos transaccionales: derivar totales por mes
        fechas = pd.to_datetime(df[col_fecha], errors='coerce')
        mes = fechas.dt.to_period('M').astype(str)
        if col_tipo is not None:
            tipos = df[col_tipo].astype(str).str.strip().str.lower()
            tipos = tipos.replace({'ingresos': 'ingreso', 'egresos': 'egreso'})
            montos = pd.to_numeric(df[col_monto] if col_monto else 0, errors='coerce')
            montos = np.where(tipos == 'egreso', -abs(montos), abs(montos))
        else:
            montos = pd.to_numeric(df[col_monto], errors='coerce')
        tmp = pd.DataFrame({'mes': mes, 'monto': montos})
        tmp = tmp.groupby('mes', as_index=False)['monto'].sum()
        tmp['ingresos'] = np.where(tmp['monto'] > 0, tmp['monto'], 0)
        tmp['egresos'] = np.where(tmp['monto'] < 0, -tmp['monto'], 0)
        tmp = tmp.drop(columns=['monto'])
    else:
        # Intentar formato de bloques lado a lado (INGRESOS | GASTOS) con columnas FECHA,MES,DESCRIPCION,VALOR
        df2 = df.copy()
        # localizar fila de encabezados: contiene 'fecha' y 'mes' y 'descripcion' y 'valor'
        header_idx = None
        for i in range(min(len(df2), 30)):
            row = [str(v).strip().lower() for v in df2.iloc[i].tolist()]
            if ('fecha' in row and 'mes' in row and any('descripcion' in x or 'descrip' in x for x in row) and 'valor' in row):
                header_idx = i
                break
        if header_idx is not None:
            # detectar posiciones de las 4 columnas del bloque izquierdo y derecho
            row = [str(v).strip().lower() for v in df2.iloc[header_idx].tolist()]
            def find_block(start_hint):
                idxs = {}
                for j in range(start_hint, len(row)):
                    v = row[j]
                    if 'fecha' == v and 'fecha' not in idxs:
                        idxs['fecha']=j
                    elif v=='mes' and 'mes' not in idxs:
                        idxs['mes']=j
                    elif (v=='descripcion' or v=='descripción') and 'desc' not in idxs:
                        idxs['desc']=j
                    elif v=='valor' and 'valor' not in idxs:
                        idxs['valor']=j
                    if len(idxs)==4:
                        break
                return idxs if len(idxs)==4 else None
            left = find_block(0)
            right = None
            if left:
                right = find_block(left['valor']+1)
            if left and right:
                body = df2.iloc[header_idx+1:].copy()
                # ingresos
                ing = body.iloc[:, [left['mes'], left['valor']]].copy()
                ing.columns = ['mes','valor']
                # egresos
                egr = body.iloc[:, [right['mes'], right['valor']]].copy()
                egr.columns = ['mes','valor']
                # normalizar meses
                def norm_mes(s):
                    s = str(s).strip().lower()
                    month_map = {'enero':1,'febrero':2,'marzo':3,'abril':4,'mayo':5,'junio':6,'julio':7,'agosto':8,'septiembre':9,'setiembre':9,'octubre':10,'noviembre':11,'diciembre':12,
                                 'ene':1,'feb':2,'mar':3,'abr':4,'may':5,'jun':6,'jul':7,'ago':8,'sep':9,'sept':9,'oct':10,'nov':11,'dic':12}
                    year = None
                    for token in s.replace('-', ' ').split():
                        if token.isdigit() and len(token)==4: year=int(token)
                    m=None
                    for k,v in month_map.items():
                        if k in s: m=v; break
                    if m is None:
                        dt = pd.to_datetime(s, errors='coerce')
                        if pd.notna(dt):
                            return dt.to_period('M').strftime('%Y-%m')
                        return None
                    if year is None:
                        now=pd.Timestamp.today()
                        year = now.year
                    return f"{year:04d}-{m:02d}"
                ing['mes'] = ing['mes'].map(norm_mes)
                egr['mes'] = egr['mes'].map(norm_mes)
                ingresos = ing.groupby('mes', as_index=False)['valor'].sum()
                egresos = egr.groupby('mes', as_index=False)['valor'].sum()
                tmp = pd.merge(ingresos, egresos, on='mes', how='outer', suffixes=('_ing','_egr')).fillna(0)
                tmp.rename(columns={'valor_ing':'ingresos','valor_egr':'egresos'}, inplace=True)
            else:
                # Intentar crosstab: filas 'ingresos'/'egresos' y columnas por mes
                df2 = df.copy()
                # Columna etiqueta por índice (evita DataFrame por duplicados)
                lidx = _label_col_index(df2)
                labels = df2.iloc[:, lidx].astype(str).str.strip().str.lower()
                row_ing = df2[labels.str.contains('ingreso') | labels.eq('ingresos')]
                # Columnas de meses: todas excepto columna etiqueta y que tengan valores numéricos
                candidate_cols = [c for j, c in enumerate(df2.columns) if j != lidx]
                month_map = {
                    'ene':1,'enero':1,
                    'feb':2,'febrero':2,
                    'mar':3,'marzo':3,
                    'abr':4,'abril':4,
                    'may':5,'mayo':5,
                    'jun':6,'junio':6,
                    'jul':7,'julio':7,
                    'ago':8,'agosto':8,
                    'sep':9,'sept':9,'septiembre':9,
                    'oct':10,'octubre':10,
                    'nov':11,'noviembre':11,
                    'dic':12,'diciembre':12,
                }
                def parse_mes(colname: str) -> str | None:
                    n = str(colname).strip().lower()
                    dt = pd.to_datetime(n, errors='coerce', dayfirst=True)
                    if pd.notna(dt):
                        return dt.to_period('M').strftime('%Y-%m')
                    parts = n.replace('-', ' ').replace('/', ' ').split()
                    if parts:
                        m = None; y = None
                        for p in parts:
                            if p.isdigit() and len(p) == 4:
                                y = int(p)
                            elif p in month_map:
                                m = month_map[p]
                        if y and m:
                            return f"{y:04d}-{m:02d}"
                    return None

                meses = []
                for c in candidate_cols:
                    if pd.to_numeric(df2[c], errors='coerce').notna().any():
                        parsed = parse_mes(c)
                        if parsed:
                            meses.append((c, parsed))
                if not meses or row_ing.empty and row_egr.empty:
                    raise ValueError('No se reconoció un esquema válido en Resumen para calcular ingresos/egresos')
                rows = []
                for c, mes_fmt in meses:
                    val_ing = pd.to_numeric(row_ing[c], errors='coerce').sum() if not row_ing.empty else 0
                    val_egr = pd.to_numeric(row_egr[c], errors='coerce').sum() if not row_egr.empty else 0
                    rows.append({'mes': mes_fmt, 'ingresos': float(val_ing), 'egresos': float(val_egr)})
                tmp = pd.DataFrame(rows)
        df2 = df.copy()
        # Columna etiqueta por índice (evita DataFrame por duplicados)
        lidx = _label_col_index(df2)
        labels = df2.iloc[:, lidx].astype(str).str.strip().str.lower()
        row_ing = df2[labels.str.contains('ingreso') | labels.eq('ingresos')]
        row_egr = df2[labels.str.contains('egreso') | labels.eq('egresos')]
        # Columnas de meses: todas excepto columna etiqueta
        candidate_cols = [c for j, c in enumerate(df2.columns) if j != lidx]
        month_map = {
            'ene':1,'enero':1,
            'feb':2,'febrero':2,
            'mar':3,'marzo':3,
            'abr':4,'abril':4,
            'may':5,'mayo':5,
            'jun':6,'junio':6,
            'jul':7,'julio':7,
            'ago':8,'agosto':8,
            'sep':9,'sept':9,'septiembre':9,
            'oct':10,'octubre':10,
            'nov':11,'noviembre':11,
            'dic':12,'diciembre':12,
        }
        def parse_mes(colname: str) -> str | None:
            n = str(colname).strip().lower()
            # Intentar YYYY-MM o MM/YYYY o M-YYYY
            dt = pd.to_datetime(n, errors='coerce', dayfirst=True)
            if pd.notna(dt):
                return dt.to_period('M').strftime('%Y-%m')
            # Intentar texto español + año
            parts = n.replace('-', ' ').replace('/', ' ').split()
            if parts:
                # buscar token mes
                m = None; y = None
                for p in parts:
                    if p.isdigit() and len(p) == 4:
                        y = int(p)
                    elif p in month_map:
                        m = month_map[p]
                if y and m:
                    return f"{y:04d}-{m:02d}"
            return None

        meses = []
        for c in candidate_cols:
            if pd.to_numeric(df2[c], errors='coerce').notna().any():
                parsed = parse_mes(c)
                if parsed:
                    meses.append((c, parsed))
        if not meses or row_ing.empty and row_egr.empty:
            raise ValueError('No se reconoció un esquema válido en Resumen para calcular ingresos/egresos')
        # Sumar por cada columna-mes
        rows = []
        for c, mes_fmt in meses:
            val_ing = pd.to_numeric(row_ing[c], errors='coerce').sum() if not row_ing.empty else 0
            val_egr = pd.to_numeric(row_egr[c], errors='coerce').sum() if not row_egr.empty else 0
            rows.append({'mes': mes_fmt, 'ingresos': float(val_ing), 'egresos': float(val_egr)})
        tmp = pd.DataFrame(rows)

    tmp['mes'] = tmp['mes'].astype(str)
    tmp = tmp.groupby('mes', as_index=False).sum(numeric_only=True)
    return tmp.rename(columns={'ingresos': 'total_ingresos', 'egresos': 'total_egresos'})


def read_resumen_hermes(path: Path, sheet_name: str = 'Informe') -> pd.DataFrame:
    # (1) lectura: Excel Resumen Hermes (Informe)
    try:
        used_sheet = sheet_name
        df = pd.read_excel(path, sheet_name=used_sheet)
    except Exception:
        prefer = [sheet_name, 'CUADRO RESUMEN TOTAL', 'VAL-REAL', 'VAL REAL', 'GANACIA', 'RESUMEN']
        used_sheet = resolve_sheet(path, prefer)
        df = pd.read_excel(path, sheet_name=used_sheet)
    if sheet_has_unnamed_columns(df):
        try:
            df = try_promote_header_from_row(path, used_sheet)
        except Exception:
            pass
    df.columns = [str(c).strip().lower() for c in df.columns]

    # Intentar detectar columnas: mes, resultado_neto
    col_mes = next((c for c in df.columns if c in ['mes', 'periodo', 'período', 'fecha']), None)
    col_neto = next((c for c in df.columns if c in ['resultado_neto', 'neto', 'resultado', 'net']), None)
    if col_mes is not None:
        # Modo simple con columna mes
        if col_neto is None:
            # Si no hay neto, intentar calcular de columnas ingresos/egresos
            col_gan = next((c for c in df.columns if c in ['ganacia','ganancia','resultado','utilidad']), None)
            if col_gan is not None:
                df['resultado_neto'] = pd.to_numeric(df[col_gan], errors='coerce')
            else:
                col_ing = next((c for c in df.columns if c in ['ingresos', 'total_ingresos','pagado']), None)
                col_egr = next((c for c in df.columns if c in ['egresos', 'total_egresos','gastos']), None)
                if col_ing is None and col_egr is None:
                    raise ValueError('No se encontró resultado_neto ni pares ingresos/egresos en Informe')
                df['resultado_neto'] = (pd.to_numeric(df[col_ing], errors='coerce') if col_ing else 0) - (pd.to_numeric(df[col_egr], errors='coerce') if col_egr else 0)
        else:
            df['resultado_neto'] = pd.to_numeric(df[col_neto], errors='coerce')
        # Normalizar mes textual en español a YYYY-MM (maneja errores comunes: 'juio', 'setiembre')
        def norm_mes(s):
            s = str(s).strip().lower()
            if s in ('', 'nan', 'none'): return None
            if s == 'total': return None
            month_map = {
                'enero':1,'febrero':2,'marzo':3,'abril':4,'mayo':5,'junio':6,'julio':7,'agosto':8,'septiembre':9,'setiembre':9,'octubre':10,'noviembre':11,'diciembre':12,
                'ene':1,'feb':2,'mar':3,'abr':4,'may':5,'jun':6,'jul':7,'ago':8,'sep':9,'sept':9,'oct':10,'nov':11,'dic':12,
                'juio':7  # error común en datos
            }
            # Intentar fecha directa
            dt = pd.to_datetime(s, errors='coerce', dayfirst=True)
            if pd.notna(dt):
                return dt.to_period('M').strftime('%Y-%m')
            # Buscar token de mes y año
            year = None; m = None
            tokens = s.replace('-', ' ').replace('/', ' ').split()
            for t in tokens:
                if t.isdigit() and len(t)==4:
                    year = int(t)
                if t in month_map:
                    m = month_map[t]
            if m is None:
                # si solo hay nombre de mes, usar año actual
                for k,v in month_map.items():
                    if k in s:
                        m = v; break
            if m is None:
                return None
            if year is None:
                year = pd.Timestamp.today().year
            return f"{year:04d}-{m:02d}"
        mes_norm = df[col_mes].map(norm_mes)
        out = pd.DataFrame({'mes': mes_norm, 'resultado_neto': df['resultado_neto']})
        out = out.dropna(subset=['mes'])
        out = out.groupby('mes', as_index=False).sum(numeric_only=True)
        out = out.sort_values('mes').reset_index(drop=True)
        return out

    # Sin columna mes: intentar estructura en columnas por mes
    df2 = df.copy()
    # Elegir columna etiqueta por índice (evita duplicados de nombre -> DataFrame)
    non_numeric_counts = {i: pd.to_numeric(df2.iloc[:, i], errors='coerce').isna().sum() for i in range(df2.shape[1])}
    lidx = max(non_numeric_counts, key=non_numeric_counts.get) if non_numeric_counts else 0
    labels = df2.iloc[:, lidx].astype(str).str.strip().str.lower()

    # Filas candidatas
    row_neto = df2[labels.str.contains('neto') | labels.str.contains('resultado')]
    row_ing = df2[labels.str.contains('ingreso') | labels.eq('ingresos')]
    row_egr = df2[labels.str.contains('egreso') | labels.eq('egresos')]

    # Reconocer columnas de meses usando ÍNDICES (evita DataFrame por nombres duplicados)
    candidate_idx = [j for j in range(df2.shape[1]) if j != lidx]
    month_map = {
        'ene':1,'enero':1,
        'feb':2,'febrero':2,
        'mar':3,'marzo':3,
        'abr':4,'abril':4,
        'may':5,'mayo':5,
        'jun':6,'junio':6,
        'jul':7,'julio':7,
        'ago':8,'agosto':8,
        'sep':9,'sept':9,'septiembre':9,
        'oct':10,'octubre':10,
        'nov':11,'noviembre':11,
        'dic':12,'diciembre':12,
    }
    def parse_mes(colname: str) -> str | None:
        n = str(colname).strip().lower()
        dt = pd.to_datetime(n, errors='coerce', dayfirst=True)
        if pd.notna(dt):
            return dt.to_period('M').strftime('%Y-%m')
        parts = n.replace('-', ' ').replace('/', ' ').split()
        if parts:
            m = None; y = None
            for p in parts:
                if p.isdigit() and len(p) == 4:
                    y = int(p)
                elif p in month_map:
                    m = month_map[p]
            if y and m:
                return f"{y:04d}-{m:02d}"
        return None

    meses = []
    for j in candidate_idx:
        col_series = df2.iloc[:, j]
        if pd.to_numeric(col_series, errors='coerce').notna().any():
            parsed = parse_mes(df2.columns[j])
            if parsed:
                meses.append((j, parsed))
    if not meses:
        cols = ', '.join(df.columns)
        raise ValueError(f'No se encontró columna mes/periodo ni columnas de meses reconocibles en Informe. Columnas: {cols}')

    rows = []
    for j, mes_fmt in meses:
        if not row_neto.empty:
            val = pd.to_numeric(row_neto.iloc[:, j], errors='coerce').sum()
        elif not row_ing.empty and not row_egr.empty:
            val = pd.to_numeric(row_ing.iloc[:, j], errors='coerce').sum() - pd.to_numeric(row_egr.iloc[:, j], errors='coerce').sum()
        else:
            # Si no hay neto ni par ingresos/egresos, usar suma total como neto
            val = pd.to_numeric(df2.iloc[:, j], errors='coerce').sum()
        rows.append({'mes': mes_fmt, 'resultado_neto': float(val)})
    out = pd.DataFrame(rows)
    out = out.groupby('mes', as_index=False).sum(numeric_only=True)
    out = out.sort_values('mes').reset_index(drop=True)
    return out


# ==========================
# (2) Carga y (3) Cálculo / (4) Actualización de tablas
# ==========================
def load_caja_chica(df: pd.DataFrame, cnx, saldo_inicial: Optional[float] = None, saldo_umbral: Optional[float] = None):
    # (3) cálculo: saldo acumulado y alerta
    saldo_inicial = SALDO_INICIAL if saldo_inicial is None else saldo_inicial
    saldo_umbral = SALDO_UMBRAL if saldo_umbral is None else saldo_umbral

    df = df.copy()
    df['saldo_actual'] = float(saldo_inicial) + df['monto'].cumsum()
    df['alerta_saldo_bajo'] = df['saldo_actual'] < float(saldo_umbral)

    # (4) actualización: insertar filas en tabla
    cur = cnx.cursor()
    cur.execute("TRUNCATE TABLE caja_chica_movimientos")
    sql = (
        "INSERT INTO caja_chica_movimientos (fecha, categoria, descripcion, monto, tipo, saldo_actual, alerta_saldo_bajo) "
        "VALUES (%s, %s, %s, %s, %s, %s, %s)"
    )
    payload = [
        (
            row['fecha'].strftime('%Y-%m-%d') if not pd.isna(row['fecha']) else None,
            str(row['categoria']) if not pd.isna(row['categoria']) else '',
            str(row['descripcion']) if not pd.isna(row['descripcion']) else '',
            float(row['monto']) if not pd.isna(row['monto']) else 0.0,
            str(row['tipo']) if not pd.isna(row['tipo']) else 'ingreso',
            float(row['saldo_actual']) if not pd.isna(row['saldo_actual']) else None,
            1 if bool(row['alerta_saldo_bajo']) else 0,
        )
        for _, row in df.iterrows()
    ]
    cur.executemany(sql, payload)
    cnx.commit()
    cur.close()


def load_resumen_ing_egr(df: pd.DataFrame, cnx):
    # (3) cálculo: totales e indicador
    tmp = df.copy()
    if 'total_ingresos' not in tmp.columns or 'total_egresos' not in tmp.columns:
        raise ValueError('El DataFrame de resumen debe contener total_ingresos y total_egresos')
    tmp['resultado_neto'] = pd.to_numeric(tmp['total_ingresos'], errors='coerce').fillna(0) - pd.to_numeric(tmp['total_egresos'], errors='coerce').fillna(0)
    tmp['alerta_resultado_negativo'] = tmp['resultado_neto'] < 0

    # (4) actualización: upsert por mes
    cur = cnx.cursor()
    sql = (
        "INSERT INTO resumen_ing_egr (mes, total_ingresos, total_egresos, resultado_neto, alerta_resultado_negativo) "
        "VALUES (%s, %s, %s, %s, %s) "
        "ON DUPLICATE KEY UPDATE total_ingresos=VALUES(total_ingresos), total_egresos=VALUES(total_egresos), "
        "resultado_neto=VALUES(resultado_neto), alerta_resultado_negativo=VALUES(alerta_resultado_negativo)"
    )
    payload = [
        (
            str(row['mes']),
            float(row['total_ingresos']) if not pd.isna(row['total_ingresos']) else 0.0,
            float(row['total_egresos']) if not pd.isna(row['total_egresos']) else 0.0,
            float(row['resultado_neto']) if not pd.isna(row['resultado_neto']) else 0.0,
            1 if bool(row['alerta_resultado_negativo']) else 0,
        )
        for _, row in tmp.iterrows()
    ]
    cur.executemany(sql, payload)
    cnx.commit()
    cur.close()


def load_resumen_hermes(df: pd.DataFrame, cnx):
    # (3) cálculo: variacion_pct mes a mes
    tmp = df.copy().sort_values('mes').reset_index(drop=True)
    tmp['resultado_neto'] = pd.to_numeric(tmp['resultado_neto'], errors='coerce').fillna(0)
    # Calcular variación respecto al mes anterior
    tmp['resultado_prev'] = tmp['resultado_neto'].shift(1)
    def calc_var(actual, prev):
        if pd.isna(prev) or prev == 0:
            return np.nan
        return (actual - prev) / abs(prev) * 100.0
    tmp['variacion_pct'] = [calc_var(a, p) for a, p in zip(tmp['resultado_neto'], tmp['resultado_prev'])]

    # (4) actualización: upsert por mes
    cur = cnx.cursor()
    sql = (
        "INSERT INTO resumen_hermes (mes, resultado_neto, variacion_pct) VALUES (%s, %s, %s) "
        "ON DUPLICATE KEY UPDATE resultado_neto=VALUES(resultado_neto), variacion_pct=VALUES(variacion_pct)"
    )
    payload = [
        (
            str(row['mes']),
            float(row['resultado_neto']) if not pd.isna(row['resultado_neto']) else 0.0,
            float(row['variacion_pct']) if not pd.isna(row['variacion_pct']) else None,
        )
        for _, row in tmp.iterrows()
    ]
    cur.executemany(sql, payload)
    cnx.commit()
    cur.close()


# ==========================
# Ejecución principal
# ==========================
def main():
    cnx = connect_db()
    try:
        cur = cnx.cursor()
        ensure_tables(cur)
        cnx.commit()
        cur.close()

        # (1) lectura: cargar y normalizar datos
        caja_df = read_caja_chica(EXCEL_CAJA, sheet_name='Movimientos')
        res_ie_df = read_ingresos_egresos(EXCEL_RESUMEN_IE, sheet_name='Resumen')
        hermes_df = read_resumen_hermes(EXCEL_HERMES, sheet_name='Informe')

        # (2) carga + (3) cálculo + (4) actualización
        load_caja_chica(caja_df, cnx)
        load_resumen_ing_egr(res_ie_df, cnx)
        load_resumen_hermes(hermes_df, cnx)

        print('Importación y cálculos completados correctamente.')
        return 0
    finally:
        cnx.close()


if __name__ == '__main__':
    raise SystemExit(main())
