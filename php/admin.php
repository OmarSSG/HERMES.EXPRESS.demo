<?php
require_once 'config.php';

/**
 * Registra una acción en el log del sistema
 * 
 * @param string $accion Nombre de la acción realizada (ej: 'crear_empleado')
 * @param int $id_entidad ID del registro afectado
 * @param string $detalles Descripción detallada de la acción
 * @return bool True si se registró correctamente, false en caso contrario
 */
function registrarAccion($accion, $id_entidad, $detalles = '') {
    global $pdo;
    
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido';
        $usuario_id = $_SESSION['usuario_id'] ?? null;
        
        // Verificar si la tabla de logs existe
        $tableExists = $pdo->query("SHOW TABLES LIKE 'logs_acciones'")->rowCount() > 0;
        
        if (!$tableExists) {
            // Si la tabla no existe, crearla
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS logs_acciones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    usuario_id INT NULL,
                    accion VARCHAR(50) NOT NULL,
                    id_entidad INT NULL,
                    detalles TEXT,
                    ip VARCHAR(45) NOT NULL,
                    user_agent TEXT,
                    fecha DATETIME NOT NULL,
                    INDEX idx_usuario (usuario_id),
                    INDEX idx_accion (accion),
                    INDEX idx_fecha (fecha)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
                ALTER TABLE logs_acciones
                ADD CONSTRAINT fk_logs_usuario
                FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
                ON DELETE SET NULL ON UPDATE CASCADE;
            ");
        }

        $stmt = $pdo->prepare("
            INSERT INTO logs_acciones 
            (usuario_id, accion, id_entidad, detalles, ip, user_agent, fecha) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        
        ");
        
        return $stmt->execute([
            $usuario_id,
            $accion,
            $id_entidad,
            $detalles,
            $ip,
            $user_agent
        ]);
        
    } catch (Exception $e) {
        // En caso de error al registrar la acción, lo registramos en el log de errores
        error_log('Error al registrar acción: ' . $e->getMessage());
        return false;
    }

}

// ===================== ESCANEO DE CODIGOS =====================
function escaneoEnsureSchema() {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS escaneo_lotes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        creado_por VARCHAR(100) NULL,
        fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ultimo_resumen TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS escaneos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lote_id INT NOT NULL,
        codigo VARCHAR(120) NOT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (lote_id) REFERENCES escaneo_lotes(id) ON DELETE CASCADE,
        INDEX idx_lote (lote_id),
        INDEX idx_codigo (codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function escaneoCrearLote() {
    global $pdo; header('Content-Type: application/json');
    try {
        escaneoEnsureSchema();
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre==='') { echo json_encode(['exito'=>false,'mensaje'=>'Nombre requerido']); return; }
        $creado_por = trim($_POST['creado_por'] ?? '');
        $stmt = $pdo->prepare("INSERT INTO escaneo_lotes (nombre, creado_por) VALUES (?, ?)");
        $stmt->execute([$nombre, $creado_por]);
        echo json_encode(['exito'=>true, 'id'=>$pdo->lastInsertId()]);
    } catch (Throwable $e) { echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
}

function escaneoListarLotes() {
    global $pdo; header('Content-Type: application/json');
    try {
        escaneoEnsureSchema();
        $r=$pdo->query("SELECT id, nombre, creado_por, fecha_creacion, ultimo_resumen FROM escaneo_lotes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['exito'=>true,'datos'=>$r]);
    } catch(Throwable $e){ echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
}

function escaneoAgregar() {
    global $pdo; header('Content-Type: application/json');
    try {
        escaneoEnsureSchema();
        $lote_id = (int)($_POST['lote_id'] ?? 0);
        $codigo = trim($_POST['codigo'] ?? '');
        if ($lote_id<=0 || $codigo==='') { echo json_encode(['exito'=>false,'mensaje'=>'Parámetros inválidos']); return; }
        $stmt = $pdo->prepare("INSERT INTO escaneos (lote_id, codigo) VALUES (?, ?)");
        $stmt->execute([$lote_id, $codigo]);
        echo json_encode(['exito'=>true]);
    } catch (Throwable $e) { echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
}

function escaneoListar() {
    global $pdo; header('Content-Type: application/json');
    try {
        escaneoEnsureSchema();
        $lote_id=(int)($_GET['lote_id']??0);
        $stmt=$pdo->prepare("SELECT id, codigo, fecha FROM escaneos WHERE lote_id=? ORDER BY id DESC");
        $stmt->execute([$lote_id]);
        echo json_encode(['exito'=>true,'datos'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Throwable $e){ echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
}

function escaneoComparar() {
    global $pdo; header('Content-Type: application/json');
    try {
        escaneoEnsureSchema();
        $lote_id = (int)($_GET['lote_id'] ?? $_POST['lote_id'] ?? 0);
        if ($lote_id<=0) { echo json_encode(['exito'=>false,'mensaje'=>'lote_id requerido']); return; }
        $solo_hoy = (int)($_GET['solo_hoy'] ?? $_POST['solo_hoy'] ?? 0) === 1;
        $en_almacen = (int)($_GET['en_almacen'] ?? $_POST['en_almacen'] ?? 0) === 1;
        $grupo = trim((string)($_GET['grupo'] ?? $_POST['grupo'] ?? ''));
        // Códigos escaneados
        $sc = $pdo->prepare("SELECT codigo FROM escaneos WHERE lote_id = ?");
        $sc->execute([$lote_id]);
        $escaneados = array_map(function($r){ return trim((string)$r['codigo']); }, $sc->fetchAll(PDO::FETCH_ASSOC));
        // Códigos en paquetes con filtros
        $where = [];
        if ($solo_hoy) { $where[] = "fecha_envio = CURDATE()"; }
        if ($en_almacen) { $where[] = "(estado IS NULL OR estado <> 'entregado')"; }
        $sqlP = "SELECT codigo, distrito FROM paquetes" . (count($where)? (' WHERE '.implode(' AND ',$where)) : '');
        $pk = $pdo->query($sqlP);
        $rows = $pk->fetchAll(PDO::FETCH_ASSOC);
        if ($grupo !== '') {
            $rows = array_values(array_filter($rows, function($r) use ($grupo){
                $g = mapDistritoAGrupo($r['distrito'] ?? '');
                return strtoupper($g ?? '') === strtoupper($grupo);
            }));
        }
        $paquetes = array_map(function($r){ return trim((string)$r['codigo']); }, $rows);
        // Conteos
        $cntEsc = array_count_values($escaneados);
        $cntPaq = array_count_values($paquetes);
        $todos = array_unique(array_merge(array_keys($cntEsc), array_keys($cntPaq)));
        $faltantes = []; $sobrantes = []; $ok = [];
        foreach ($todos as $c) {
            $a = (int)($cntEsc[$c] ?? 0);
            $b = (int)($cntPaq[$c] ?? 0);
            if ($a === $b && $a>0) $ok[] = ['codigo'=>$c, 'cantidad'=>$a];
            elseif ($a < $b) $faltantes[] = ['codigo'=>$c, 'esperado'=>$b, 'encontrado'=>$a];
            elseif ($a > $b) $sobrantes[] = ['codigo'=>$c, 'esperado'=>$b, 'encontrado'=>$a];
        }
        $resumen = [
            'total_escaneados' => array_sum($cntEsc),
            'total_paquetes' => array_sum($cntPaq),
            'coincidentes' => count($ok),
            'faltantes' => count($faltantes),
            'sobrantes' => count($sobrantes)
        ];
        // Guardar resumen en lote
        $upd = $pdo->prepare("UPDATE escaneo_lotes SET ultimo_resumen = ? WHERE id = ?");
        $upd->execute([json_encode($resumen), $lote_id]);
        echo json_encode(['exito'=>true, 'resumen'=>$resumen, 'faltantes'=>$faltantes, 'sobrantes'=>$sobrantes]);
    } catch (Throwable $e) { echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
}

function seedRutas() {
    global $pdo;
    try {
        // asegurar columna zonas
        try { $pdo->exec("ALTER TABLE rutas ADD COLUMN zonas TEXT NULL"); } catch (Exception $e) { }

        $rutas = [
            ['nombre'=>'URBANO', 'zonas'=> 'Chiclayo, Leonardo Ortiz, La Victoria, Santa Victoria'],
            ['nombre'=>'PUEBLOS', 'zonas'=> 'Lambayeque, Mochumi, Tucume, Illimo, Nueva Arica, Jayanca, Pacora, Morrope, Motupe, Olmos, Salas'],
            ['nombre'=>'PLAYAS', 'zonas'=> 'San Jose, Santa Rosa, Pimentel, Reque, Monsefu, Eten, Puerto Eten'],
            ['nombre'=>'COOPERATIVAS', 'zonas'=> 'Pomalca, Tuman, Patapo, Pucala, Saltur, Chongoyape'],
            ['nombre'=>'EXCOOPERATIVA', 'zonas'=> 'Ucupe, Mocupec, Zaña, Cayalti, Oyotun, Lagunas'],
            ['nombre'=>'FERREÑAFE', 'zonas'=> 'Ferreñafe, Picsi, Pitipo, Motupillo, Pueblo Nuevo']
        ];

        // correcciones ortográficas según imágenes
        $reemplazos = [
            'Mocupec' => 'Mocupe'
        ];
        foreach ($rutas as &$r) {
            foreach ($reemplazos as $a=>$b) { $r['zonas'] = str_replace($a, $b, $r['zonas']); }
        }

        // upsert por nombre
        $select = $pdo->prepare('SELECT id FROM rutas WHERE nombre = ?');
        $insert = $pdo->prepare('INSERT INTO rutas (nombre, origen, destino, distancia, tiempo_estimado, zonas) VALUES (?,?,?,?,?,?)');
        $update = $pdo->prepare('UPDATE rutas SET zonas = ? WHERE id = ?');

        $creadas = 0; $actualizadas = 0;
        foreach ($rutas as $r) {
            $select->execute([$r['nombre']]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $update->execute([$r['zonas'], $row['id']]);
                $actualizadas++;
            } else {
                $insert->execute([$r['nombre'], '', '', 0, 0, $r['zonas']]);
                $creadas++;
            }
        }

        echo json_encode(['exito'=>true, 'creadas'=>$creadas, 'actualizadas'=>$actualizadas]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false, 'mensaje'=>'Error al sembrar rutas: '.$e->getMessage()]);
    }
}

// Verificar sesión de administrador o asistente
session_start();
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['tipo'], ['admin', 'asistente'])) {
    echo json_encode(['exito' => false, 'mensaje' => 'Acceso no autorizado']);
    exit;
}

// ============ Finanzas: Importación y consultas ============
function finanzasImportar() {
    header('Content-Type: application/json');
    try {
        $base = realpath(__DIR__ . '/..');
        $script = $base . DIRECTORY_SEPARATOR . 'import_finanzas.py';
        if (!file_exists($script)) {
            echo json_encode(['exito'=>false,'mensaje'=>'Script import_finanzas.py no encontrado']);
            return;
        }
        // Permitir configurar saldo inicial y umbral por POST (opcional)
        $env = [];
        if (isset($_POST['saldo_inicial'])) { $env['SALDO_INICIAL'] = (string)$_POST['saldo_inicial']; }
        if (isset($_POST['saldo_umbral'])) { $env['SALDO_UMBRAL'] = (string)$_POST['saldo_umbral']; }

        // Probar distintas invocaciones de Python en Windows/Linux
        $intentos = [
            'py -3 -u %s',
            'py -u %s',
            'python -u %s',
            'python3 -u %s',
        ];
        $resultados = [];
        $exitCode = null;
        foreach ($intentos as $fmt) {
            $cmd = sprintf($fmt, escapeshellarg($script)) . ' 2>&1';
            $salida = [];
            $codigo = 0;
            // Preparar entorno
            $procEnv = null;
            if (!empty($env)) {
                $procEnv = [];
                foreach ($env as $k=>$v) { $procEnv[$k] = $v; }
            }
            if ($procEnv !== null && function_exists('proc_open')) {
                $descriptor = [1=>['pipe','w'], 2=>['pipe','w']];
                $proc = @proc_open($cmd, $descriptor, $pipes, $base, $procEnv);
                if (is_resource($proc)) {
                    $out = stream_get_contents($pipes[1]);
                    $err = stream_get_contents($pipes[2]);
                    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
                    $codigo = proc_close($proc);
                    $salida = explode("\n", trim($out . "\n" . $err));
                } else {
                    $salida = ['No se pudo iniciar el proceso con proc_open'];
                    $codigo = 1;
                }
            } else {
                @exec($cmd, $salida, $codigo);
            }
            $resultados[] = [ 'cmd' => $cmd, 'codigo' => $codigo, 'salida' => $salida ];
            if ($codigo === 0) { $exitCode = 0; break; }
        }

        echo json_encode([
            'exito' => $exitCode === 0,
            'codigo' => $exitCode === null ? -1 : $exitCode,
            'intentos' => $resultados,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false,'mensaje'=>'Error al importar: '.$e->getMessage()]);
    }
}

function finanzasCaja() {
    global $pdo;
    header('Content-Type: application/json');
    try {
        // Si la tabla no existe, devolver vacío
        $exists = $pdo->query("SHOW TABLES LIKE 'caja_chica_movimientos'")->rowCount() > 0;
        if (!$exists) { echo json_encode(['exito'=>true,'datos'=>[]]); return; }
        $stmt = $pdo->query("SELECT fecha, categoria, descripcion, monto, tipo, saldo_actual, alerta_saldo_bajo FROM caja_chica_movimientos ORDER BY fecha ASC, id ASC");
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['exito'=>true,'datos'=>$datos]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false,'mensaje'=>'Error al obtener caja chica: '.$e->getMessage()]);
    }
}

function finanzasResumenIE() {
    global $pdo;
    header('Content-Type: application/json');
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'resumen_ing_egr'")->rowCount() > 0;
        if (!$exists) { echo json_encode(['exito'=>true,'datos'=>[]]); return; }
        $stmt = $pdo->query("SELECT mes, total_ingresos, total_egresos, resultado_neto, alerta_resultado_negativo FROM resumen_ing_egr ORDER BY mes ASC");
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['exito'=>true,'datos'=>$datos]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false,'mensaje'=>'Error al obtener resumen IE: '.$e->getMessage()]);
    }
}

function finanzasResumenHermes() {
    global $pdo;
    header('Content-Type: application/json');
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'resumen_hermes'")->rowCount() > 0;
        if (!$exists) { echo json_encode(['exito'=>true,'datos'=>[]]); return; }
        $stmt = $pdo->query("SELECT mes, resultado_neto, variacion_pct FROM resumen_hermes ORDER BY mes ASC");
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['exito'=>true,'datos'=>$datos]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false,'mensaje'=>'Error al obtener resumen Hermes: '.$e->getMessage()]);
    }
}


$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

switch($accion) {
    case 'listar_archivos_excel':
        header('Content-Type: application/json');
        echo json_encode(['exito' => true, 'archivos' => listarArchivosExcel()]);
        exit;
        break;
    case 'metricas':
        obtenerMetricasAdmin();
        break;
    case 'todos_paquetes':
        obtenerTodosLosPaquetes();
        break;
    case 'empleados':
        obtenerEmpleados();
        break;
    case 'vehiculos':
        obtenerVehiculos();
        break;
    case 'rutas':
        obtenerRutas();
        break;
    case 'crear_ruta':
        crearRuta();
        break;
    case 'actualizar_ruta':
        actualizarRuta();
        break;
    case 'seed_rutas':
        seedRutas();
        break;
    case 'nuevo_empleado':
        crearEmpleado();
        break;
    case 'nuevo_vehiculo':
        crearVehiculo();
        break;
    case 'nueva_ruta':
        crearRuta();
        break;
    case 'actualizar_empleado':
        actualizarEmpleado();
        break;
    case 'eliminar_paquete':
        eliminarPaquete();
        break;
    case 'nuevo_paquete':
        crearPaquete();
        break;
    case 'eliminar_empleado':
        eliminarEmpleado();
        break;
    case 'eliminar_vehiculo':
        eliminarVehiculo();
        break;
    case 'eliminar_ruta':
        eliminarRuta();
        break;
    case 'generar_reporte': 
        generarReporte();
        break;
    case 'asignar_paquetes_auto':
        asignarPaquetesAuto();
        break;
    case 'finanzas_importar':
        finanzasImportar();
        break;
    case 'finanzas_caja':
        finanzasCaja();
        break;
    case 'finanzas_resumen_ie':
        finanzasResumenIE();
        break;
    case 'finanzas_resumen_hermes':
        finanzasResumenHermes();
        break;
    case 'tarifas_listar':
        tarifasListar();
        break;
    case 'tarifas_crear':
        tarifasCrear();
        break;
    case 'tarifas_actualizar':
        tarifasActualizar();
        break;
    case 'tarifas_eliminar':
        tarifasEliminar();
        break;
    case 'tarifas_aplicar_paquetes':
        tarifasAplicarPaquetes();
        break;
    case 'tarifas_agrupadas':
        tarifasAgrupadas();
        break;
    case 'reasignar_paquete':
        reasignarPaquete();
        break;
    case 'asignar_por_distritos':
        asignarPorDistritos();
        break;
    case 'escaneo_crear_lote':
        escaneoCrearLote();
        break;
    case 'escaneo_listar_lotes':
        escaneoListarLotes();
        break;
    case 'escaneo_agregar':
        escaneoAgregar();
        break;
    case 'escaneo_listar':
        escaneoListar();
        break;
    case 'escaneo_comparar':
        escaneoComparar();
        break;
    case 'sin_asignar':
        listarPaquetesSinAsignar();
        break;
    case 'resumen_asignados':
        resumenAsignados();
        break;
    case 'limpiar_asignaciones':
        limpiarAsignaciones();
        break;
    default:
        echo json_encode(['exito' => false, 'mensaje' => 'Acción no válida']);
}

function obtenerMetricasAdmin() {
    global $pdo;
    
    try {
        // Total de paquetes
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM paquetes");
        $total_paquetes = $stmt->fetch()['total'];
        
        // Ingresos del mes actual
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(precio), 0) as total 
            FROM paquetes 
            WHERE MONTH(fecha_envio) = MONTH(CURDATE()) 
            AND YEAR(fecha_envio) = YEAR(CURDATE())
        ");
        $ingresos_mes = $stmt->fetch()['total'];
        
        // Empleados activos
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE tipo = 'empleado' AND activo = 1");
        $empleados_activos = $stmt->fetch()['total'];
        
        // Vehículos operativos
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM vehiculos WHERE estado = 'disponible'");
        $vehiculos_operativos = $stmt->fetch()['total'];
        
        echo json_encode([
            'exito' => true,
            'datos' => [
                'total_paquetes' => $total_paquetes,
                'ingresos_mes' => $ingresos_mes,
                'empleados_activos' => $empleados_activos,
                'vehiculos_operativos' => $vehiculos_operativos
            ]
        ]);
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener métricas']);
    }
}

function obtenerTodosLosPaquetes() {
    global $pdo;
    
    try {
        // Si las tablas no existen, devolver vacío sin error
        $hasPaquetes = $pdo->query("SHOW TABLES LIKE 'paquetes'")->rowCount() > 0;
        $hasPaquetesJson = $pdo->query("SHOW TABLES LIKE 'paquetes_json'")->rowCount() > 0;
        if (!$hasPaquetes && !$hasPaquetesJson) {
            echo json_encode(['exito'=>true, 'datos'=>[]]);
            return;
        }
        // Asegurar columnas necesarias (idempotente)
        if ($hasPaquetes) { try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN empleado_id INT NULL"); } catch (Exception $e) {} }
        if ($hasPaquetesJson) { try { $pdo->exec("ALTER TABLE paquetes_json ADD COLUMN empleado_id INT NULL"); } catch (Exception $e) {} }
        // Preparar paths para JSON en paquetes_json (coincidir con asignación)
        $pathDistrito = "COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.Distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.DISTRITO')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.data.distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.data.Distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.destino.distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.destino.Distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.direccion.distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.direccion.Distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.Direccion.distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.Direccion.Distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.DireccionDestino.distrito')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.DireccionDestino.Distrito'))
        )";
        $pathDireccion = "COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.DireccionDestino')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.Direccion')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.direccion_destino')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.direccion'))
        )";
        $pathDepartamento = "COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.departamento')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.Departamento')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.DEPARTAMENTO'))
        )";
        $pathProvincia = "COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.provincia')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.Provincia')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.PROVINCIA'))
        )";
        $pathEstado = "COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.estado')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.Estado')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.ESTADO'))
        )";
        $pathPeso = "COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.peso')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.Peso')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.PESO'))
        )";
        $pathTelefono = "COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.telefono')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.Teléfono')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.TELEFONO')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.celular')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.Celular')),
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.CELULAR'))
        )";

        $sql = "
            SELECT p.id,
                   p.codigo,
                   p.departamento,
                   p.provincia,
                   p.distrito,
                   p.estado,
                   p.destinatario AS consignado,
                   p.direccion_consignado AS direccion_consignado,
                   p.peso,
                   p.telefono,
                   p.empleado_id, u.nombre AS empleado_nombre, u.zona AS zona, 'paquetes' AS origen
            FROM paquetes p
            LEFT JOIN usuarios u ON p.empleado_id = u.id
            UNION ALL
            SELECT pj.id,
                   JSON_UNQUOTE(JSON_EXTRACT(pj.data, '$.Codigo')) AS codigo,
                   $pathDepartamento AS departamento,
                   $pathProvincia AS provincia,
                   TRIM(COALESCE(NULLIF($pathDistrito, ''), NULLIF(TRIM(SUBSTRING_INDEX($pathDireccion, ',', -1)), ''))) AS distrito,
                   $pathEstado AS estado,
                   COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pj.data, '$.Cliente')), JSON_UNQUOTE(JSON_EXTRACT(pj.data, '$.Destinatario'))) AS consignado,
                   $pathDireccion AS direccion_consignado,
                   $pathPeso AS peso,
                   $pathTelefono AS telefono,
                   pj.empleado_id,
                   u2.nombre AS empleado_nombre,
                   u2.zona AS zona,
                   'paquetes_json' AS origen
            FROM paquetes_json pj
            LEFT JOIN usuarios u2 ON pj.empleado_id = u2.id
        ";
        $paquetes = [];
        try {
            $stmt = $pdo->query($sql);
            $paquetes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Si la consulta falla por esquema, devolver vacío para no romper el front
            $paquetes = [];
        }
        
        echo json_encode([
            'exito' => true,
            'datos' => $paquetes
        ]);
    } catch(PDOException $e) {
        echo json_encode(['exito' => true, 'datos' => []]);
    }
}

function obtenerEmpleados() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT id, nombre, usuario, email, tipo, activo, fecha_creacion
            FROM usuarios 
            WHERE tipo IN ('empleado', 'asistente', 'admin')
            ORDER BY nombre
        ");
        $empleados = $stmt->fetchAll();
        
        echo json_encode([
            'exito' => true,
            'datos' => $empleados
        ]);
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener empleados']);
    }
}

function obtenerVehiculos() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT v.*, 
                   COALESCE(u.nombre, 'Sin asignar') as empleado_nombre,
                   CASE 
                       WHEN v.estado = 'disponible' THEN 'Disponible'
                       WHEN v.estado = 'en_ruta' THEN 'En ruta'
                       WHEN v.estado = 'mantenimiento' THEN 'En mantenimiento'
                       ELSE v.estado
                   END as estado_mostrar
            FROM vehiculos v
            LEFT JOIN usuarios u ON v.empleado_id = u.id
            ORDER BY v.placa
        
        ");
        $vehiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Asegurarse de que los datos estén en el formato correcto
        foreach ($vehiculos as &$vehiculo) {
            $vehiculo['id'] = (int)$vehiculo['id'];
            $vehiculo['capacidad'] = (float)$vehiculo['capacidad'];
        }
        
        echo json_encode([
            'exito' => true,
            'datos' => $vehiculos
        ], JSON_UNESCAPED_UNICODE);
    } catch(PDOException $e) {
        error_log('Error en obtenerVehiculos: ' . $e->getMessage());
        echo json_encode([
            'exito' => false, 
            'mensaje' => 'Error al obtener vehículos: ' . $e->getMessage()
        ]);
    }
}

function obtenerRutas() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT * FROM rutas 
            ORDER BY nombre
        ");
        $rutas = $stmt->fetchAll();
        
        echo json_encode([
            'exito' => true,
            'datos' => $rutas
        ]);
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener rutas']);
    }
}

function actualizarRuta() {
    global $pdo;
    try {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $nombre = isset($_POST['nombre']) ? limpiar_dato($_POST['nombre']) : '';
        $origen = isset($_POST['origen']) ? limpiar_dato($_POST['origen']) : null;
        $destino = isset($_POST['destino']) ? limpiar_dato($_POST['destino']) : null;
        $distancia = isset($_POST['distancia']) ? $_POST['distancia'] : null;
        $tiempo_estimado = isset($_POST['tiempo_estimado']) ? $_POST['tiempo_estimado'] : null;
        $zonas = isset($_POST['zonas']) ? trim($_POST['zonas']) : null; // coma-separado
        
        if ($id <= 0) {
            echo json_encode(['exito' => false, 'mensaje' => 'ID inválido']);
            return;
        }

        // Asegurar columna 'zonas' (opcional)
        try {
            $pdo->exec("ALTER TABLE rutas ADD COLUMN zonas TEXT NULL");
        } catch (Exception $e) {
            // Ignorar si ya existe
        }

        // Construir SET dinámico
        $campos = [];
        $params = [];
        if ($nombre !== '') { $campos[] = 'nombre = ?'; $params[] = $nombre; }
        if ($origen !== null) { $campos[] = 'origen = ?'; $params[] = $origen; }
        if ($destino !== null) { $campos[] = 'destino = ?'; $params[] = $destino; }
        if ($distancia !== null && $distancia !== '') { $campos[] = 'distancia = ?'; $params[] = (float)$distancia; }
        if ($tiempo_estimado !== null && $tiempo_estimado !== '') { $campos[] = 'tiempo_estimado = ?'; $params[] = (int)$tiempo_estimado; }
        if ($zonas !== null) { $campos[] = 'zonas = ?'; $params[] = $zonas; }

        if (!count($campos)) {
            echo json_encode(['exito' => false, 'mensaje' => 'No hay cambios para aplicar']);
            return;
        }

        $params[] = $id;
        $sql = 'UPDATE rutas SET ' . implode(', ', $campos) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['exito' => true, 'mensaje' => 'Ruta actualizada exitosamente']);
    } catch (PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al actualizar ruta']);
    }
}

function crearEmpleado() {
    global $pdo;
    
    // Establecer el tipo de contenido de la respuesta
    header('Content-Type: application/json');
    
    try {
        // Obtener y validar los datos de entrada
        $datos = $_POST;
        
        // Validar campos obligatorios
        $camposRequeridos = ['nombre', 'usuario', 'email', 'clave', 'tipo', 'zona'];
        $camposFaltantes = [];
        
        foreach ($camposRequeridos as $campo) {
            if (empty(trim($datos[$campo] ?? ''))) {
                $camposFaltantes[] = $campo;
            }
        }
        
        if (!empty($camposFaltantes)) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'exito' => false, 
                'mensaje' => 'Los siguientes campos son obligatorios: ' . implode(', ', $camposFaltantes)
            ]);
            return;
        }
        
        // Limpiar y validar los datos
        $nombre = limpiar_dato($datos['nombre']);
        $usuario = limpiar_dato($datos['usuario']);
        $email = filter_var(limpiar_dato($datos['email']), FILTER_VALIDATE_EMAIL);
        $clave = $datos['clave'];
        $tipo = limpiar_dato($datos['tipo']);
        $zona = strtoupper(trim(limpiar_dato($datos['zona'])));
        $zonasPermitidas = ['URBANO','PUEBLOS','PLAYAS','COOPERATIVAS','EXCOOPERATIVA','FERREÑAFE'];
        if (!in_array($zona, $zonasPermitidas, true)) {
            http_response_code(400);
            echo json_encode(['exito' => false, 'mensaje' => 'Zona inválida']);
            return;
        }
        // Asegurar columna zona en usuarios
        try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN zona VARCHAR(50) NULL"); } catch (Exception $e) { /* ya existe */ }
        
        // Validar formato de email
        if (!$email) {
            http_response_code(400);
            echo json_encode(['exito' => false, 'mensaje' => 'El formato del correo electrónico no es válido']);
            return;
        }
        
        // Validar longitud mínima de contraseña
        if (strlen($clave) < 6) {
            http_response_code(400);
            echo json_encode(['exito' => false, 'mensaje' => 'La contraseña debe tener al menos 6 caracteres']);
            return;
        }
        
        // Verificar si el usuario o email ya existen
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ? OR email = ?");
        $stmt->execute([$usuario, $email]);
        $usuarioExistente = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuarioExistente) {
            http_response_code(409); // Conflict
            echo json_encode([
                'exito' => false, 
                'mensaje' => 'El nombre de usuario o correo electrónico ya está en uso',
                'campo' => $usuarioExistente['usuario'] === $usuario ? 'usuario' : 'email'
            ]);
            return;
        }
        
        // Hashear la contraseña
        $clave_hash = password_hash($clave, PASSWORD_DEFAULT);
        
        // Iniciar transacción
        $pdo->beginTransaction();
        
        try {
            // Insertar el nuevo empleado
            $stmt = $pdo->prepare("
                INSERT INTO usuarios (nombre, usuario, email, clave, tipo, zona, activo, fecha_registro) 
                VALUES (:nombre, :usuario, :email, :clave, :tipo, :zona, 1, NOW())
            ");
            
            $stmt->execute([
                ':nombre' => $nombre,
                ':usuario' => $usuario,
                ':email' => $email,
                ':clave' => $clave_hash,
                ':tipo' => $tipo,
                ':zona' => $zona
            ]);
            
            $id_empleado = $pdo->lastInsertId();
            
            // Si todo salió bien, confirmar la transacción
            $pdo->commit();
            
            // Registrar la acción en el log
            registrarAccion('crear_empleado', $id_empleado, "Se creó el empleado: $nombre ($usuario)");
            
            // Devolver respuesta exitosa
            http_response_code(201); // Created
            echo json_encode([
                'exito' => true,
                'mensaje' => 'Empleado creado exitosamente',
                'id_empleado' => $id_empleado,
                'fecha_registro' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            // Revertir la transacción en caso de error
            $pdo->rollBack();
            throw $e;
        }
        
    } catch(PDOException $e) {
        error_log('Error en crearEmpleado: ' . $e->getMessage());
        echo json_encode([
            'exito' => false, 
            'mensaje' => 'Error en el servidor: ' . $e->getMessage()
        ]);
    }
}

function crearVehiculo() {
    global $pdo;
    
    try {
        $placa = limpiar_dato($_POST['placa']);
        $marca = limpiar_dato($_POST['marca']);
        $modelo = limpiar_dato($_POST['modelo']);
        $capacidad = floatval($_POST['capacidad']);
        $estado = limpiar_dato($_POST['estado']);
        
        // Validar datos
        if (empty($placa) || empty($marca) || empty($modelo) || $capacidad <= 0) {
            echo json_encode(['exito' => false, 'mensaje' => 'Todos los campos son obligatorios']);
            return;
        }
        
        // Verificar si la placa ya existe
        $stmt = $pdo->prepare("SELECT id FROM vehiculos WHERE placa = ?");
        $stmt->execute([$placa]);
        if ($stmt->fetch()) {
            echo json_encode(['exito' => false, 'mensaje' => 'La placa ya existe']);
            return;
        }
        
        // Crear vehículo
        $stmt = $pdo->prepare("
            INSERT INTO vehiculos (placa, marca, modelo, capacidad, estado) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$placa, $marca, $modelo, $capacidad, $estado]);
        
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Vehículo creado exitosamente'
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al crear vehículo']);
    }
}

function crearRuta() {
    global $pdo;
    
    try {
        $nombre = limpiar_dato($_POST['nombre']);
        $origen = limpiar_dato($_POST['origen']);
        $destino = limpiar_dato($_POST['destino']);
        $distancia = floatval($_POST['distancia']);
        $tiempo_estimado = intval($_POST['tiempo_estimado']);
        $zonas = isset($_POST['zonas']) ? trim($_POST['zonas']) : null; // coma-separado
        
        // Validar datos
        if (empty($nombre) || empty($origen) || empty($destino) || $distancia <= 0) {
            echo json_encode(['exito' => false, 'mensaje' => 'Todos los campos son obligatorios']);
            return;
        }
        
        // Asegurar columna 'zonas' (opcional)
        try {
            $pdo->exec("ALTER TABLE rutas ADD COLUMN zonas TEXT NULL");
        } catch (Exception $e) {
            // Ignorar si ya existe
        }

        // Crear ruta (incluye zonas si se proporcionó)
        if ($zonas !== null && $zonas !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO rutas (nombre, origen, destino, distancia, tiempo_estimado, zonas) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $origen, $destino, $distancia, $tiempo_estimado, $zonas]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO rutas (nombre, origen, destino, distancia, tiempo_estimado) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $origen, $destino, $distancia, $tiempo_estimado]);
        }
        
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Ruta creada exitosamente'
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al crear ruta']);
    }
}

function actualizarEmpleado() {
    global $pdo;
    
    try {
        $id = intval($_POST['id']);
        $activo = intval($_POST['activo']);
        
        $stmt = $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
        $stmt->execute([$activo, $id]);
        
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Empleado actualizado exitosamente'
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al actualizar empleado']);
    }
}

function eliminarPaquete() {
    global $pdo;
    
    try {
        $id = intval($_POST['id']);
        
        $stmt = $pdo->prepare("DELETE FROM paquetes WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Paquete eliminado exitosamente'
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al eliminar paquete']);
    }
}

function crearPaquete() {
    global $pdo;
    
    try {
        // Asegurar columna distrito para soportar asignación por zonas
        try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN distrito VARCHAR(100) NULL"); } catch (Exception $e) { /* ya existe */ }

        $remitente = limpiar_dato($_POST['remitente'] ?? '');
        $destinatario = limpiar_dato($_POST['destinatario'] ?? '');
        $direccion_origen = limpiar_dato($_POST['direccion_origen'] ?? '');
        $direccion_destino = limpiar_dato($_POST['direccion_destino'] ?? '');
        $peso = floatval($_POST['peso'] ?? 0);
        $precio = floatval($_POST['precio'] ?? 0);
        $tipo_ruta = limpiar_dato($_POST['tipo_ruta'] ?? '');
        $distrito_in = limpiar_dato($_POST['distrito'] ?? '');
        // Derivar distrito del destino si no viene explícito: tomar el último segmento tras la última coma
        $distrito = $distrito_in;
        if ($distrito === '' && $direccion_destino !== '') {
            $partes = explode(',', $direccion_destino);
            $ultimo = trim(end($partes));
            $distrito = $ultimo;
        }
        
        if (empty($remitente) || empty($destinatario) || empty($direccion_origen) || empty($direccion_destino) || $peso <= 0 || $precio <= 0 || empty($tipo_ruta)) {
            echo json_encode(['exito' => false, 'mensaje' => 'Todos los campos son obligatorios y deben ser válidos']);
            return;
        }
        
        $codigo = 'PKG' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Autoprecio desde tarifas si no viene precio o es 0
        if ($precio <= 0) {
            $precio = obtenerPrecioTarifaPorDistrito($distrito) ?? 0;
        }

        $stmt = $pdo->prepare("
            INSERT INTO paquetes (codigo, remitente, destinatario, direccion_origen, direccion_destino, distrito, peso, precio, tipo_ruta, estado, fecha_envio) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', CURDATE())
        ");
        
        $stmt->execute([$codigo, $remitente, $destinatario, $direccion_origen, $direccion_destino, $distrito, $peso, $precio, $tipo_ruta]);
        
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Paquete creado exitosamente',
            'codigo' => $codigo
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al crear paquete: ' . $e->getMessage()]);
    }
}

function eliminarEmpleado() {
    global $pdo;
    
    try {
        $id = intval($_POST['id']);
        
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND tipo != 'admin'");
        $stmt->execute([$id]);
        
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Empleado eliminado exitosamente'
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al eliminar empleado']);
    }
}

function eliminarVehiculo() {
    global $pdo;
    
    try {
        $id = intval($_POST['id']);
        
        $stmt = $pdo->prepare("DELETE FROM vehiculos WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Vehículo eliminado exitosamente'
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al eliminar vehículo']);
    }
}

function eliminarRuta() {
    global $pdo;
    
    try {
        $id = intval($_POST['id']);
        
        $stmt = $pdo->prepare("DELETE FROM rutas WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Ruta eliminada exitosamente'
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al eliminar ruta']);
    }
}



function generarReporte() {
    global $pdo;
    
    try {
        $tipo = $_POST['tipo'] ?? $_GET['tipo'];
        $fecha_inicio = $_POST['fecha_inicio'] ?? $_GET['fecha_inicio'] ?? null;
        $fecha_fin = $_POST['fecha_fin'] ?? $_GET['fecha_fin'] ?? null;
        $formato = $_POST['formato'] ?? $_GET['formato'] ?? 'ver';
        
        $datos = [];
        
        switch($tipo) {
            case 'paquetes':
                $sql = "SELECT * FROM paquetes";
                if ($fecha_inicio && $fecha_fin) {
                    $sql .= " WHERE fecha_envio BETWEEN ? AND ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$fecha_inicio, $fecha_fin]);
                } else {
                    $stmt = $pdo->query($sql);
                }
                $datos = $stmt->fetchAll();
                break;
                
            case 'ventas':
                $sql = "SELECT DATE(fecha_envio) as fecha, COUNT(*) as total_paquetes, SUM(precio) as ingresos FROM paquetes";
                if ($fecha_inicio && $fecha_fin) {
                    $sql .= " WHERE fecha_envio BETWEEN ? AND ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$fecha_inicio, $fecha_fin]);
                } else {
                    $stmt = $pdo->query($sql);
                }
                $sql .= " GROUP BY DATE(fecha_envio) ORDER BY fecha DESC";
                $stmt = $pdo->prepare($sql);
                if ($fecha_inicio && $fecha_fin) {
                    $stmt->execute([$fecha_inicio, $fecha_fin]);
                } else {
                    $stmt->execute();
                }
                $datos = $stmt->fetchAll();
                break;
                
            case 'empleados':
                $sql = "SELECT * FROM usuarios WHERE tipo IN ('empleado', 'admin', 'asistente')";
                if ($fecha_inicio && $fecha_fin) {
                    $sql .= " AND fecha_registro BETWEEN ? AND ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$fecha_inicio, $fecha_fin]);
                } else {
                    $stmt = $pdo->query($sql);
                }
                $datos = $stmt->fetchAll();
                break;
                
            case 'vehiculos':
                $sql = "SELECT v.*, u.nombre as empleado_nombre FROM vehiculos v LEFT JOIN usuarios u ON v.empleado_asignado = u.id";
                $stmt = $pdo->query($sql);
                $datos = $stmt->fetchAll();
                break;
                
            case 'empleados_paquetes':
                $sql = "SELECT u.nombre, u.email, u.tipo, 
                        COUNT(p.id) as total_paquetes,
                        SUM(CASE WHEN p.estado = 'entregado' THEN 1 ELSE 0 END) as paquetes_entregados,
                        SUM(CASE WHEN p.estado = 'devuelto' THEN 1 ELSE 0 END) as paquetes_devueltos,
                        SUM(p.precio) as ingresos_generados
                        FROM usuarios u 
                        LEFT JOIN paquetes p ON u.id = p.empleado_id
                        WHERE u.tipo IN ('empleado', 'asistente')";
                if ($fecha_inicio && $fecha_fin) {
                    $sql .= " AND p.fecha_envio BETWEEN ? AND ?";
                    $stmt = $pdo->prepare($sql . " GROUP BY u.id");
                    $stmt->execute([$fecha_inicio, $fecha_fin]);
                } else {
                    $stmt = $pdo->prepare($sql . " GROUP BY u.id");
                    $stmt->execute();
                }
                $datos = $stmt->fetchAll();
                break;
                
            case 'rutas_paquetes':
                $sql = "SELECT r.nombre as ruta, r.origen, r.destino, r.distancia,
                        0 as total_paquetes,
                        0 as entregados,
                        0 as ingresos
                        FROM rutas r";
                $stmt = $pdo->query($sql);
                $datos = $stmt->fetchAll();
                break;
                
            case 'vehiculos_entregas':
                $sql = "SELECT v.placa, v.marca, v.modelo, v.estado,
                        0 as total_entregas,
                        0 as entregas_exitosas,
                        u.nombre as conductor
                        FROM vehiculos v 
                        LEFT JOIN usuarios u ON v.empleado_id = u.id";
                $stmt = $pdo->query($sql);
                $datos = $stmt->fetchAll();
                break;
        }
        
        // Si es descarga, generar archivo
        if ($formato === 'excel') {
            generarExcel($datos, $tipo);
            return;
        } elseif ($formato === 'pdf') {
            generarPDF($datos, $tipo);
            return;
        }
        
        // Si es solo ver, devolver JSON
        echo json_encode([
            'exito' => true,
            'datos' => $datos
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al generar reporte: ' . $e->getMessage()]);
    }
}

function generarExcel($datos, $tipo) {
    // Configurar headers para descarga de CSV (compatible con Excel)
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_' . $tipo . '_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: max-age=0');
    
    // Crear contenido CSV
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    if (!empty($datos)) {
        // Escribir encabezados traducidos
        $headers = array_keys((array)$datos[0]);
        $headersTraducidos = [];
        foreach ($headers as $header) {
            $headersTraducidos[] = ucfirst(str_replace('_', ' ', $header));
        }
        fputcsv($output, $headersTraducidos, ',');
        
        // Escribir datos
        foreach ($datos as $fila) {
            $filaArray = array_values((array)$fila);
            fputcsv($output, $filaArray, ',');
        }
    } else {
        fputcsv($output, ['Sin datos para mostrar'], ',');
    }
    
    fclose($output);
    exit;
}

function generarPDF($datos, $tipo) {
    // Configurar headers para descarga de HTML (que se puede imprimir como PDF)
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_' . $tipo . '_' . date('Y-m-d') . '.html"');
    
    // Crear HTML bien formateado
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte ' . ucfirst($tipo) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .fecha { text-align: center; margin-bottom: 20px; color: #666; }
        @media print {
            body { margin: 0; }
        }
    </style>
</head>
<body>
    <h1>Reporte de ' . ucfirst(str_replace('_', ' ', $tipo)) . '</h1>
    <div class="fecha">Generado el: ' . date('d/m/Y H:i:s') . '</div>';
    
    if (!empty($datos)) {
        $html .= '<table><thead><tr>';
        
        // Encabezados
        $headers = array_keys((array)$datos[0]);
        foreach ($headers as $header) {
            $html .= '<th>' . ucfirst(str_replace('_', ' ', $header)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        
        // Datos
        foreach ($datos as $fila) {
            $html .= '<tr>';
            foreach ((array)$fila as $valor) {
                $html .= '<td>' . htmlspecialchars($valor ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<p>No hay datos para mostrar en este reporte.</p>';
    }
    
    $html .= '
    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>';
    
    echo $html;
    exit;
}
// Asignación automática de paquetes por zona/distrito
function asignarPaquetesAuto() {
    global $pdo;
    header('Content-Type: application/json');
    try {
        // Asegurar columnas necesarias (idempotente)
        try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN distrito VARCHAR(100) NULL"); } catch (Exception $e) { }
        try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN empleado_id INT NULL"); } catch (Exception $e) { }
        // Soporte para paquetes_json
        try { $pdo->exec("ALTER TABLE paquetes_json ADD COLUMN empleado_id INT NULL"); } catch (Exception $e) { }

        // Construir mapa distrito -> zona (nombre de ruta) con normalización
        $rutas = $pdo->query("SELECT nombre, zonas FROM rutas")->fetchAll(PDO::FETCH_ASSOC);
        // Fallback en memoria (siempre se fusiona para asegurar cobertura de zonas clave)
        $fallback = [
            ['nombre'=>'URBANO', 'zonas'=> 'Chiclayo, Leonardo Ortiz, La Victoria, Santa Victoria'],
            ['nombre'=>'PUEBLOS', 'zonas'=> 'Lambayeque, Mochumi, Tucume, Illimo, Nueva Arica, Jayanca, Pacora, Morrope, Motupe, Olmos, Salas'],
            ['nombre'=>'PLAYAS', 'zonas'=> 'San Jose, Santa Rosa, Pimentel, Reque, Monsefu, Eten, Puerto Eten'],
            ['nombre'=>'COOPERATIVAS', 'zonas'=> 'Pomalca, Tuman, Patapo, Pucala, Saltur, Chongoyape'],
            ['nombre'=>'EXCOOPERATIVAS', 'zonas'=> 'Ucupe, Mocupe, Zaña, Saña, Cayalti, Oyotun, Lagunas'],
            ['nombre'=>'FERREÑAFE', 'zonas'=> 'Ferreñafe, Picsi, Pitipo, Motupillo, Pueblo Nuevo']
        ];
        $rutas = array_merge(is_array($rutas)?$rutas:[], $fallback);
        $mapDistritoZona = [];
        $normalizar = function($str) {
            $s = trim((string)$str);
            if (function_exists('mb_strtolower')) {
                $s = mb_strtolower($s, 'UTF-8');
            } else {
                $s = strtolower($s);
            }
            // reemplazos básicos de acentos y ñ
            $s = strtr($s, [
                'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
                'ñ'=>'n','Ñ'=>'n'
            ]);
            // limpiar signos/puntuación raros
            $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
            // normalizar espacios
            $s = preg_replace('/\s+/', ' ', trim($s));
            // sinónimos/comunes exactos
            $syn = [
                'jose leonardo ortiz' => 'leonardo ortiz',
                'j.l. ortiz' => 'leonardo ortiz',
                'j l ortiz' => 'leonardo ortiz',
                'ferrenafe' => 'ferrenafe',
                'sana' => 'zana'
            ];
            if (isset($syn[$s])) return $syn[$s];
            // equivalencia por contención (p.ej. "sector sana centro" -> zana)
            if (strpos($s, 'sana') !== false) return 'zana';
            return $s;
        };
        foreach ($rutas as $r) {
            $zonas = array_map('trim', explode(',', (string)$r['zonas']));
            foreach ($zonas as $z) {
                if ($z !== '') {
                    $key = $normalizar($z);
                    $mapDistritoZona[$key] = strtoupper(trim($r['nombre']));
                }
            }
        }

        // Paquetes sin asignar con distrito (tabla paquetes)
        $paquetes = $pdo->query("SELECT id, distrito, 'paquetes' AS origen FROM paquetes WHERE (empleado_id IS NULL OR empleado_id = 0) AND distrito IS NOT NULL AND TRIM(distrito) <> ''")->fetchAll(PDO::FETCH_ASSOC);
        // Paquetes sin asignar con distrito desde paquetes_json (leer desde data->distrito)
        try {
            // Intentar múltiples rutas posibles para 'distrito' (keys en distintos formatos y anidaciones comunes)
            $pathDistrito = "COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.Distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.DISTRITO')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.data.distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.data.Distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.destino.distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.destino.Distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.direccion.distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.direccion.Distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.Direccion.distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.Direccion.Distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.DireccionDestino.distrito')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.DireccionDestino.Distrito'))
            )";
            // Fallback: derivar distrito desde campos de dirección tomando el último segmento tras comas
            $pathDireccion = "COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.DireccionDestino')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.Direccion')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.direccion_destino')),
                JSON_UNQUOTE(JSON_EXTRACT(data, '$.direccion'))
            )";
            $sqlJson = "SELECT id,
                            TRIM(
                                COALESCE(
                                    NULLIF($pathDistrito, ''),
                                    NULLIF(TRIM(SUBSTRING_INDEX($pathDireccion, ',', -1)), '')
                                )
                            ) AS distrito,
                            'paquetes_json' AS origen
                        FROM paquetes_json
                        WHERE (empleado_id IS NULL OR empleado_id = 0)
                          AND (
                                ($pathDistrito IS NOT NULL AND TRIM($pathDistrito) <> '')
                                OR ($pathDireccion IS NOT NULL AND TRIM($pathDireccion) <> '')
                              )";
            $paquetesJson = $pdo->query($sqlJson)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $paquetesJson = [];
        }
        $paquetes = array_merge($paquetes, $paquetesJson);
        $asignados = 0; $sinRuta = 0; $sinEmpleado = 0; $detalles = [];

        foreach ($paquetes as $p) {
            $d = $normalizar($p['distrito']);
            $zona = $mapDistritoZona[$d] ?? null;
            if (!$zona) { $sinRuta++; $detalles[] = ['paquete'=>$p['id'], 'motivo'=>'sin_ruta_para_distrito', 'distrito'=>$p['distrito']]; continue; }

            // Empleado activo de esa zona con menor carga
            $stmt = $pdo->prepare("
                SELECT u.id, COALESCE(SUM(p2.estado IN ('pendiente','en_ruta')),0) AS carga
                FROM usuarios u
                LEFT JOIN paquetes p2 ON p2.empleado_id = u.id
                WHERE u.activo = 1 AND u.tipo IN ('empleado','asistente') AND UPPER(u.zona) = ?
                GROUP BY u.id
                ORDER BY carga ASC, u.id ASC
                LIMIT 1
            ");
            $stmt->execute([$zona]);
            $empleado = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$empleado) { $sinEmpleado++; $detalles[] = ['paquete'=>$p['id'], 'motivo'=>'sin_empleado_en_zona', 'zona'=>$zona]; continue; }

            if (($p['origen'] ?? 'paquetes') === 'paquetes_json') {
                $upd = $pdo->prepare("UPDATE paquetes_json SET empleado_id = ? WHERE id = ?");
                $upd->execute([(int)$empleado['id'], (int)$p['id']]);
            } else {
                $upd = $pdo->prepare("UPDATE paquetes SET empleado_id = ? WHERE id = ?");
                $upd->execute([(int)$empleado['id'], (int)$p['id']]);
            }
            $asignados++;
        }

        echo json_encode(['exito'=>true, 'asignados'=>$asignados, 'sin_ruta'=>$sinRuta, 'sin_empleado'=>$sinEmpleado, 'total'=>count($paquetes), 'detalle'=>$detalles]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false, 'mensaje'=>'Error en asignación: '.$e->getMessage()]);
    }
}

// Reasignación manual de paquete a empleado específico
function reasignarPaquete() {
    global $pdo;
    header('Content-Type: application/json');
    try {
        $paquete_id = isset($_POST['paquete_id']) ? (int)$_POST['paquete_id'] : 0;
        $empleado_id = isset($_POST['empleado_id']) ? (int)$_POST['empleado_id'] : 0;
        if ($paquete_id <= 0 || $empleado_id <= 0) { echo json_encode(['exito'=>false, 'mensaje'=>'Parámetros inválidos']); return; }

        // Validar empleado
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND activo = 1");
        $stmt->execute([$empleado_id]);
        if (!$stmt->fetch()) { echo json_encode(['exito'=>false, 'mensaje'=>'Empleado no válido']); return; }

        $upd = $pdo->prepare("UPDATE paquetes SET empleado_id = ? WHERE id = ?");
        $upd->execute([$empleado_id, $paquete_id]);

        echo json_encode(['exito'=>true, 'mensaje'=>'Paquete reasignado']);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false, 'mensaje'=>'Error al reasignar: '.$e->getMessage()]);
    }
}

// Limpia asignaciones huérfanas: si el empleado no existe, deja empleado_id = NULL
function limpiarAsignaciones(){
    global $pdo; header('Content-Type: application/json');
    try {
        // Asegurar columnas (idempotente)
        try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN empleado_id INT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE paquetes_json ADD COLUMN empleado_id INT NULL"); } catch (Exception $e) {}

        // Contar antes
        $c1 = 0; $c2 = 0;
        try { $c1 = (int)($pdo->query("SELECT COUNT(*) c FROM paquetes p LEFT JOIN usuarios u ON p.empleado_id=u.id WHERE p.empleado_id IS NOT NULL AND u.id IS NULL")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0); } catch (Throwable $e) {}
        try { $c2 = (int)($pdo->query("SELECT COUNT(*) c FROM paquetes_json pj LEFT JOIN usuarios u ON pj.empleado_id=u.id WHERE pj.empleado_id IS NOT NULL AND u.id IS NULL")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0); } catch (Throwable $e) {}

        // Limpiar
        try { $pdo->exec("UPDATE paquetes p LEFT JOIN usuarios u ON p.empleado_id = u.id SET p.empleado_id = NULL WHERE p.empleado_id IS NOT NULL AND u.id IS NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("UPDATE paquetes_json pj LEFT JOIN usuarios u ON pj.empleado_id = u.id SET pj.empleado_id = NULL WHERE pj.empleado_id IS NOT NULL AND u.id IS NULL"); } catch (Throwable $e) {}

        echo json_encode([ 'exito'=>true, 'limpiados'=>[ 'paquetes'=>$c1, 'paquetes_json'=>$c2 ] ]);
    } catch (Throwable $e) {
        echo json_encode([ 'exito'=>false, 'mensaje'=>$e->getMessage() ]);
    }
}

// ===================== ARCHIVOS EXCEL DESCARGADOS =====================
function listarArchivosExcel() {
    $downloadsDir = __DIR__ . '/../downloads';
    $archivos = [];
    
    if (is_dir($downloadsDir)) {
        // Obtener todos los archivos del directorio
        $files = [];
        $xlsFiles = glob($downloadsDir . '/*.xls');
        $xlsxFiles = glob($downloadsDir . '/*.xlsx');
        $files = array_merge($xlsFiles, $xlsxFiles);
        
        // Array para almacenar la información de los archivos
        $archivosData = [];
        
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            
            $fileInfo = pathinfo($file);
            $rutaRelativa = 'HERMES.EXPRESS/downloads/' . $fileInfo['basename'];
            $rutaCompleta = realpath($file);
            
            // Solo agregar si el archivo existe y es legible
            if ($rutaCompleta !== false && is_readable($rutaCompleta)) {
                $archivosData[] = [
                    'nombre' => $fileInfo['basename'],
                    'ruta' => $rutaRelativa,
                    'ruta_completa' => str_replace('\\', '/', $rutaCompleta),
                    'tamano' => filesize($file),
                    'fecha_modificacion' => filemtime($file), // Guardamos el timestamp directamente
                    'fecha_formateada' => date('Y-m-d H:i:s', filemtime($file)),
                    'tamano_formateado' => formatBytes(filesize($file))
                ];
            }
        }
        
        // Ordenar por fecha de modificación (más reciente primero)
        usort($archivosData, function($a, $b) {
            return $b['fecha_modificacion'] - $a['fecha_modificacion'];
        });
        
        // Mapear al formato final
        foreach ($archivosData as $archivo) {
            $archivos[] = [
                'nombre' => $archivo['nombre'],
                'ruta' => $archivo['ruta'],
                'ruta_completa' => $archivo['ruta_completa'],
                'tamano' => $archivo['tamano'],
                'fecha_modificacion' => $archivo['fecha_formateada'],
                'tamano_formateado' => $archivo['tamano_formateado']
            ];
        }
    }
    
    return $archivos;
}

// Función auxiliar para formatear tamaños de archivo
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// ===================== TARIFAS POR ZONA =====================
function tarifasEnsureSchema() {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS tarifas_zonas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grupo VARCHAR(50) NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        unidad VARCHAR(20) NOT NULL DEFAULT 'Paquete',
        precio DECIMAL(10,2) NOT NULL DEFAULT 0,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uniq_grupo_nombre (grupo, nombre),
        INDEX idx_grupo (grupo),
        INDEX idx_nombre (nombre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tarifasSeedIfEmpty() {
    global $pdo;
    tarifasEnsureSchema();
    $cnt = (int)$pdo->query("SELECT COUNT(*) AS c FROM tarifas_zonas")->fetch(PDO::FETCH_ASSOC)['c'];
    if ($cnt > 0) return;
    $data = [];
    // URBANO
    foreach ([['Chiclayo',1], ['Leonardo Ortiz',1], ['La Victoria',1], ['Santa Victoria',1]] as $r) {
        $data[] = ['URBANO',$r[0],'Paquete',$r[1],1];
    }
    // PUEBLOS
    foreach ([['Lambayeque',3], ['Mochumi',5], ['Tucume',5], ['Illimo',5], ['Nueva Arica',5], ['Jayanca',5], ['Pacora',5], ['Morrope',5], ['Motupe',5], ['Olmos',5], ['Salas',5]] as $r) {
        $data[] = ['PUEBLOS',$r[0],'Paquete',$r[1],1];
    }
    // PLAYAS
    foreach ([['San Jose',3], ['Santa Rosa',3], ['Pimentel',3], ['Reque',3], ['Monsefu',3], ['Eten',5], ['Puerto Eten',5]] as $r) {
        $data[] = ['PLAYAS',$r[0],'Paquete',$r[1],1];
    }
    // COOPERATIVAS
    foreach ([['Pomalca',3], ['Tuman',5], ['Patapo',5], ['Pucala',5], ['Sartur',5], ['Chongoyape',5]] as $r) {
        $data[] = ['COOPERATIVAS',$r[0],'Paquete',$r[1],1];
    }
    // EXCOOPERATIVAS
    foreach ([['Ucupe',5], ['Mocupe',5], ['Zaña',5], ['Cayalti',5], ['Oyutun',5], ['Lagunas',5]] as $r) {
        $data[] = ['EXCOOPERATIVAS',$r[0],'Paquete',$r[1],1];
    }
    // FERREÑAFE
    foreach ([['Ferreñafe',5], ['Picsi',5], ['Pitipo',5], ['Motupillo',5], ['Pueblo Nuevo',5]] as $r) {
        $data[] = ['FERREÑAFE',$r[0],'Paquete',$r[1],1];
    }
    $ins = $pdo->prepare("INSERT INTO tarifas_zonas (grupo,nombre,unidad,precio,activo) VALUES (?,?,?,?,?)");
    foreach ($data as $row) { $ins->execute($row); }
}

function tarifasListar() {
    global $pdo;
    header('Content-Type: application/json');
    try {
        tarifasSeedIfEmpty();
        $grupo = isset($_GET['grupo']) ? trim($_GET['grupo']) : '';
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $sql = "SELECT id, grupo, nombre, unidad, precio, activo FROM tarifas_zonas WHERE 1=1";
        $params = [];
        if ($grupo !== '') { $sql .= " AND grupo = ?"; $params[] = $grupo; }
        if ($q !== '') { $sql .= " AND (nombre LIKE ? OR grupo LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
        $sql .= " ORDER BY grupo, nombre";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        echo json_encode(['exito'=>true, 'datos'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false,'mensaje'=>'Error al listar tarifas: '.$e->getMessage()]);
    }
}

function tarifasCrear() {
    global $pdo; header('Content-Type: application/json');
    try {
        tarifasEnsureSchema();
        $grupo = trim($_POST['grupo'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $precio = (float)($_POST['precio'] ?? 0);
        $activo = (int)($_POST['activo'] ?? 1);
        if ($grupo==='' || $nombre==='') { echo json_encode(['exito'=>false,'mensaje'=>'Grupo y nombre son obligatorios']); return; }
        $stmt = $pdo->prepare("INSERT INTO tarifas_zonas (grupo,nombre,unidad,precio,activo) VALUES (?,?,?,?,?)");
        $stmt->execute([$grupo, $nombre, 'Paquete', $precio, $activo]);
        echo json_encode(['exito'=>true, 'id'=>$pdo->lastInsertId()]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false,'mensaje'=>'Error al crear tarifa: '.$e->getMessage()]);
    }
}

function tarifasActualizar() {
    global $pdo; header('Content-Type: application/json');
    try {
        tarifasEnsureSchema();
        $id = (int)($_POST['id'] ?? 0);
        if ($id<=0) { echo json_encode(['exito'=>false,'mensaje'=>'ID inválido']); return; }
        $campos = [];$params=[];
        if (isset($_POST['grupo'])) { $campos[]='grupo=?'; $params[] = trim($_POST['grupo']); }
        if (isset($_POST['nombre'])) { $campos[]='nombre=?'; $params[] = trim($_POST['nombre']); }
        if (isset($_POST['precio'])) { $campos[]='precio=?'; $params[] = (float)$_POST['precio']; }
        if (isset($_POST['activo'])) { $campos[]='activo=?'; $params[] = (int)$_POST['activo']; }
        if (!count($campos)) { echo json_encode(['exito'=>false,'mensaje'=>'Sin cambios']); return; }
        $params[] = $id;
        $sql = 'UPDATE tarifas_zonas SET '.implode(',', $campos).' WHERE id = ?';
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        echo json_encode(['exito'=>true]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false,'mensaje'=>'Error al actualizar tarifa: '.$e->getMessage()]);
    }
}

function tarifasEliminar() {
    global $pdo; header('Content-Type: application/json');
    try {
        tarifasEnsureSchema();
        $id = (int)($_POST['id'] ?? 0);
        if ($id<=0) { echo json_encode(['exito'=>false,'mensaje'=>'ID inválido']); return; }
        $stmt = $pdo->prepare('DELETE FROM tarifas_zonas WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['exito'=>true]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false,'mensaje'=>'Error al eliminar tarifa: '.$e->getMessage()]);
    }
}

// ===== Helpers y aplicación de tarifas a paquetes =====
function normalizar_simple($str) {
    $s = trim((string)$str);
    if (function_exists('mb_strtolower')) $s = mb_strtolower($s,'UTF-8'); else $s = strtolower($s);
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u','ñ'=>'n','Ñ'=>'n']);
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', trim($s));
    $syn = [ 'jose leonardo ortiz'=>'leonardo ortiz','j.l. ortiz'=>'leonardo ortiz','j l ortiz'=>'leonardo ortiz','ferrenafe'=>'ferreñafe','sana'=>'zaña' ];
    return $syn[$s] ?? $s;
}

function mapDistritoAGrupo($distrito) {
    global $pdo;
    $rutas = $pdo->query("SELECT nombre, zonas FROM rutas")->fetchAll(PDO::FETCH_ASSOC);
    $fallback = [
        ['nombre'=>'URBANO', 'zonas'=> 'Chiclayo, Leonardo Ortiz, La Victoria, Santa Victoria'],
        ['nombre'=>'PUEBLOS', 'zonas'=> 'Lambayeque, Mochumi, Tucume, Illimo, Nueva Arica, Jayanca, Pacora, Morrope, Motupe, Olmos, Salas'],
        ['nombre'=>'PLAYAS', 'zonas'=> 'San Jose, Santa Rosa, Pimentel, Reque, Monsefu, Eten, Puerto Eten'],
        ['nombre'=>'COOPERATIVAS', 'zonas'=> 'Pomalca, Tuman, Patapo, Pucala, Saltur, Chongoyape'],
        ['nombre'=>'EXCOOPERATIVA', 'zonas'=> 'Ucupe, Mocupe, Zaña, Saña, Cayalti, Oyotun, Lagunas'],
        ['nombre'=>'FERREÑAFE', 'zonas'=> 'Ferreñafe, Picsi, Pitipo, Motupillo, Pueblo Nuevo']
    ];
    $rutas = array_merge(is_array($rutas)?$rutas:[], $fallback);
    $target = normalizar_simple($distrito);
    foreach ($rutas as $r) {
        $zonas = array_map('trim', explode(',', (string)$r['zonas']));
        foreach ($zonas as $z) {
            if (normalizar_simple($z) === $target) return strtoupper(trim($r['nombre']));
        }
    }
    return null;
}

function obtenerPrecioTarifaPorDistrito($distrito) {
    global $pdo;
    tarifasEnsureSchema();
    $grupo = mapDistritoAGrupo($distrito);
    if (!$grupo) return null;
    $stmt = $pdo->prepare("SELECT nombre, precio FROM tarifas_zonas WHERE grupo = ? AND activo = 1");
    $stmt->execute([$grupo]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $target = normalizar_simple($distrito);
    foreach ($rows as $r) {
        if (normalizar_simple($r['nombre']) === $target) return (float)$r['precio'];
    }
    return null;
}

function tarifasAplicarPaquetes() {
    global $pdo; header('Content-Type: application/json');
    try {
        tarifasEnsureSchema();
        try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN distrito VARCHAR(100) NULL"); } catch (Exception $e) {}
        $sel = $pdo->query("SELECT id, distrito, precio FROM paquetes");
        $paqs = $sel->fetchAll(PDO::FETCH_ASSOC);
        $upd = $pdo->prepare("UPDATE paquetes SET precio = ? WHERE id = ?");
        $aplicados=0; $sinPrecio=0; $sinGrupo=0; $total=count($paqs);
        foreach ($paqs as $p) {
            $d = trim((string)$p['distrito']);
            if ($d === '') { $sinGrupo++; continue; }
            $precio = obtenerPrecioTarifaPorDistrito($d);
            if ($precio === null) { $sinPrecio++; continue; }
            if ((float)$p['precio'] !== (float)$precio) {
                $upd->execute([$precio, (int)$p['id']]);
                $aplicados++;
            }
        }
        echo json_encode(['exito'=>true, 'total'=>$total, 'actualizados'=>$aplicados, 'sin_precio'=>$sinPrecio, 'sin_grupo'=>$sinGrupo]);
    } catch (Throwable $e) {
        echo json_encode(['exito'=>false, 'mensaje'=>'Error aplicando tarifas: '.$e->getMessage()]);
    }
}
?>