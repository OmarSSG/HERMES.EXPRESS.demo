import os
import json
import sys
import shutil
from pathlib import Path
from datetime import datetime
from typing import List, Dict, Any
import hashlib

import pandas as pd
import mysql.connector as mysql

# Config DB via variables de entorno
DB_HOST = os.environ.get('DB_HOST', '127.0.0.1')
DB_USER = os.environ.get('DB_USER', 'root')
DB_PASS = os.environ.get('DB_PASS', '')
DB_NAME = os.environ.get('DB_NAME', 'hermes_express')
TABLE_NAME = os.environ.get('PAQUETES_TABLE', 'paquetes_json')
DOWNLOADS_DIR = Path(__file__).parent / 'downloads'


def find_all_excels(downloads: Path) -> List[Path]:
    """Retorna todos los .xls y .xlsx en la carpeta downloads, ordenados por fecha (más recientes primero)."""
    files = sorted(list(downloads.glob('*.xls*')), key=lambda p: p.stat().st_mtime, reverse=True)
    if not files:
        raise FileNotFoundError('No se encontraron archivos Excel en downloads/')
    return files


def read_excel_rows(path: Path) -> List[Dict[str, Any]]:
    """Lee la primera hoja del Excel y devuelve filas como dict, con metadatos de archivo y hoja."""
    # Leer primera hoja completa (todo como texto para no perder formatos)
    df = pd.read_excel(path, sheet_name=0, dtype=str)
    df = df.fillna('')
    rows = df.to_dict(orient='records')
    hoja = 0
    for r in rows:
        # Metadatos útiles para trazabilidad
        r.setdefault('_archivo', path.name)
        r.setdefault('_hoja', hoja)
    return rows


def connect_db():
    cnx = mysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        autocommit=False,
    )
    return cnx


def ensure_table(cursor):
    cursor.execute(
        f"""
        CREATE TABLE IF NOT EXISTS `{TABLE_NAME}` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `data` JSON NOT NULL,
            `hash` VARCHAR(64) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_hash` (`hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        """
    )
    # Si la tabla ya existía sin columna hash, intentar agregarla (idempotente)
    try:
        cursor.execute(f"ALTER TABLE `{TABLE_NAME}` ADD COLUMN IF NOT EXISTS `hash` VARCHAR(64) NOT NULL AFTER `data`;")
    except Exception:
        pass
    try:
        cursor.execute(f"ALTER TABLE `{TABLE_NAME}` ADD UNIQUE KEY `uniq_hash` (`hash`);")
    except Exception:
        pass


def insert_rows(rows: List[Dict[str, Any]]) -> int:
    if not rows:
        return 0
    cnx = connect_db()
    try:
        cur = cnx.cursor()
        ensure_table(cur)
        # Inserción con IGNORE y hash único para evitar duplicados
        sql = f"INSERT IGNORE INTO `{TABLE_NAME}` (`data`, `hash`) VALUES (%s, %s)"
        payload = []
        for r in rows:
            # Normalizar JSON con sort_keys para hash estable
            normalized = json.dumps(r, ensure_ascii=False, sort_keys=True)
            h = hashlib.sha256(normalized.encode('utf-8')).hexdigest()
            payload.append((json.dumps(r, ensure_ascii=False), h))
        cur.executemany(sql, payload)
        inserted = cur.rowcount
        cnx.commit()
        return inserted
    finally:
        try:
            cur.close()
        except Exception:
            pass
        cnx.close()


def main():
    try:
        # Asegurar que el directorio de descargas exista
        DOWNLOADS_DIR.mkdir(parents=True, exist_ok=True)
        
        # Obtener la ruta al escritorio del usuario
        desktop = Path.home() / 'Desktop'
        
        # Buscar archivos Excel en el escritorio (último modificado primero)
        excel_files = list(desktop.glob('*.xls*'))
        excel_files.sort(key=lambda x: x.stat().st_mtime, reverse=True)
        
        if not excel_files:
            print('No se encontraron archivos Excel en el escritorio')
            return 1
            
        # Tomar el archivo más reciente
        source_file = excel_files[0]
        print(f'Archivo encontrado: {source_file}')
        
        # Crear un nombre único para el archivo en la carpeta de descargas
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        dest_file = DOWNLOADS_DIR / f"{timestamp}_{source_file.name}"
        
        # Copiar el archivo a la carpeta de descargas
        import shutil
        shutil.copy2(str(source_file), str(dest_file))
        print(f'Archivo copiado a: {dest_file}')
        
        # Procesar el archivo copiado
        print(f'Procesando archivo: {dest_file}')
        rows = read_excel_rows(dest_file)
        
        if not rows:
            print('El archivo está vacío o no se pudieron leer los datos')
            return 1
            
        # Insertar en la base de datos
        inserted = insert_rows(rows)
        print(f'Se insertaron {inserted} registros en la base de datos')
        
        return 0
        
    except Exception as e:
        print(f'Error: {str(e)}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
