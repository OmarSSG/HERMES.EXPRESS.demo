<?php
require_once 'config.php';
session_start();

// Configuración de la base de datos
$servidor = "localhost";
$usuario_db = "root";
$clave_db = "";
$base_datos = "hermes_express";

// Verificar si el usuario está autenticado y es administrador
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['tipo']) || $_SESSION['tipo'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Acceso no autorizado. Se requiere ser administrador.']);
    exit;
}

// Configurar cabeceras CORS
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$response = [];

// Obtener la acción desde la URL
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    $pdo = new PDO("mysql:host=$servidor;dbname=$base_datos;charset=utf8", $usuario_db, $clave_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Asegurar columna zona en usuarios para todos los casos (idempotente)
    try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN zona VARCHAR(50) NULL"); } catch (Exception $e) {}

    switch ($action) {
        case 'obtener':
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($id <= 0) {
                throw new Exception('ID de usuario no válido');
            }
            
            $stmt = $pdo->prepare("SELECT id, usuario, nombre, email, tipo, zona, activo FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$usuario) {
                throw new Exception('Usuario no encontrado');
            }
            
            echo json_encode($usuario);
            break;
            
        case 'listar':
            try {
                // Obtener el total de registros
                $stmtTotal = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
                $totalRegistros = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total'];
                
                // Construir la consulta base
                $sql = "SELECT id, usuario, nombre, email, tipo, zona, activo, fecha_creacion 
                        FROM usuarios ";
                
                // Aplicar búsqueda si existe
                $where = [];
                $params = [];
                
                if (!empty($_GET['search']['value'])) {
                    $search = '%' . $_GET['search']['value'] . '%';
                    $where[] = "(usuario LIKE ? OR nombre LIKE ? OR email LIKE ?)";
                    $params = array_merge($params, [$search, $search, $search]);
                }
                
                if (!empty($where)) {
                    $sql .= " WHERE " . implode(" AND ", $where);
                }
                
                // Obtener el total filtrado
                $stmtFiltrado = $pdo->prepare("SELECT COUNT(*) as total FROM usuarios " . (!empty($where) ? " WHERE " . implode(" AND ", $where) : ""));
                $stmtFiltrado->execute($params);
                $totalFiltrado = $stmtFiltrado->fetch(PDO::FETCH_ASSOC)['total'];
                
                // Aplicar ordenación
                $order = " ORDER BY ";
                if (isset($_GET['order']) && count($_GET['order'])) {
                    $orderBy = [];
                    // Mantener índices esperados por la tabla (sin contar zona)
                    $columnas = [
                        0 => 'id',
                        1 => 'usuario',
                        2 => 'nombre',
                        3 => 'email',
                        4 => 'tipo',
                        5 => 'activo',
                        6 => 'fecha_creacion'
                    ];
                    
                    foreach ($_GET['order'] as $orden) {
                        $columna = $orden['column'];
                        $dir = $orden['dir'];
                        if (isset($columnas[$columna])) {
                            $orderBy[] = $columnas[$columna] . ' ' . $dir;
                        }
                    }
                    
                    $order .= !empty($orderBy) ? implode(", ", $orderBy) : "fecha_creacion DESC";
                } else {
                    $order .= "fecha_creacion DESC";
                }
                
                $sql .= $order;
                
                // Aplicar paginación
                if (isset($_GET['start']) && $_GET['length'] != -1) {
                    $sql .= sprintf(" LIMIT %d, %d", (int)$_GET['start'], (int)$_GET['length']);
                }
                
                // Ejecutar consulta final
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Formatear la respuesta para DataTables
                $response = [
                    'draw' => isset($_GET['draw']) ? intval($_GET['draw']) : 1,
                    'recordsTotal' => (int)$totalRegistros,
                    'recordsFiltered' => (int)$totalFiltrado,
                    'data' => $usuarios
                ];
                
                header('Content-Type: application/json');
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode([
                    'exito' => false,
                    'mensaje' => 'Error al obtener los usuarios: ' . $e->getMessage(),
                    'data' => []
                ]);
            }
            break;

        case 'crear':
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data)) {
                // fallback a form-encoded
                $data = $_POST;
            }
            // Asegurar columna zona (idempotente)
            try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN zona VARCHAR(50) NULL"); } catch (Exception $e) {}
            
            // Validar datos (zona solo obligatoria para empleados)
            $usuario = isset($data['usuario']) ? trim($data['usuario']) : '';
            $nombre  = isset($data['nombre'])  ? trim($data['nombre'])  : '';
            $email   = isset($data['email'])   ? trim($data['email'])   : '';
            $tipo    = isset($data['tipo'])    ? trim($data['tipo'])    : '';
            $clave   = isset($data['clave'])   ? (string)$data['clave'] : '';
            $faltantes = [];
            if ($usuario === '') $faltantes[] = 'usuario';
            if ($nombre === '')  $faltantes[] = 'nombre';
            if ($email === '')   $faltantes[] = 'email';
            if ($tipo === '')    $faltantes[] = 'tipo';
            if ($clave === '')   $faltantes[] = 'clave';
            if (!empty($faltantes)) {
                throw new Exception('Campos obligatorios faltantes: ' . implode(', ', $faltantes));
            }
            $zona = isset($data['zona']) ? strtoupper(trim($data['zona'])) : null;
            $tiposPermitidos = ['admin','asistente','empleado'];
            if (!in_array($tipo, $tiposPermitidos, true)) {
                throw new Exception('Tipo de usuario inválido');
            }
            $zonasPermitidas = ['URBANO','PUEBLOS','PLAYAS','COOPERATIVAS','EXCOOPERATIVA','EXCOOPERATIVAS','FERREÑAFE'];
            if ($tipo === 'empleado') {
                if (empty($zona)) {
                    throw new Exception('La zona es obligatoria para empleados');
                }
                if (!in_array($zona, $zonasPermitidas, true)) {
                    throw new Exception('Zona inválida');
                }
            } else {
                // Para admin/asistente, permitir zona nula
                $zona = null;
            }
            
            // Verificar si el usuario ya existe
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $stmt->execute([$usuario]);
            if ($stmt->rowCount() > 0) {
                throw new Exception('El nombre de usuario ya está en uso');
            }
            
            // Encriptar contraseña
            $clave_hash = password_hash($clave, PASSWORD_DEFAULT);
            
            // Insertar nuevo usuario
            $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, clave, nombre, email, tipo, zona, activo) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $usuario,
                $clave_hash,
                $nombre,
                $email,
                $tipo,
                $zona,
                (isset($data['activo']) && ($data['activo'] === 1 || $data['activo'] === '1' || $data['activo'] === true || $data['activo'] === 'on')) ? 1 : 0
            ]);
            
            $response = ['success' => 'Usuario creado correctamente'];
            echo json_encode($response);
            break;

        case 'actualizar':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if ($id <= 0) {
                throw new Exception('ID de usuario no válido');
            }
            
            // Verificar si el usuario existe
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                throw new Exception('Usuario no encontrado');
            }
            
            // Construir la consulta de actualización
            $updates = [];
            $params = [];
            
            if (!empty($data['nombre'])) $updates[] = 'nombre = ?';
            if (!empty($data['email'])) $updates[] = 'email = ?';
            if (!empty($data['tipo'])) $updates[] = 'tipo = ?';
            if (!empty($data['zona'])) $updates[] = 'zona = ?';
            if (isset($data['activo'])) $updates[] = 'activo = ?';
            
            // Si se proporciona una nueva contraseña, actualizarla
            if (!empty($data['clave'])) {
                $updates[] = 'clave = ?';
                $params[] = password_hash($data['clave'], PASSWORD_DEFAULT);
            }
            
            if (empty($updates)) {
                throw new Exception('No se proporcionaron datos para actualizar');
            }
            
            // Agregar los parámetros en el orden correcto
            // Orden de parámetros según $updates construidos
            if (!empty($data['nombre'])) $params[] = $data['nombre'];
            if (!empty($data['email'])) $params[] = $data['email'];
            if (!empty($data['tipo'])) $params[] = $data['tipo'];
            if (!empty($data['zona'])) $params[] = strtoupper(trim($data['zona']));
            $params[] = $id;
            
            // Construir y ejecutar la consulta
            $sql = "UPDATE usuarios SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $response = ['success' => 'Usuario actualizado correctamente'];
            echo json_encode($response);
            break;

        case 'eliminar':
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($id <= 0) { throw new Exception('ID de usuario no válido'); }
            if ($id === 1) { throw new Exception('No se puede eliminar el administrador principal'); }

            // Desasignar paquetes del usuario y luego eliminarlo (transacción)
            $pdo->beginTransaction();
            try {
                // Asegurar columnas empleado_id existen (idempotente)
                try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN empleado_id INT NULL"); } catch (Throwable $e) { }
                try { $pdo->exec("ALTER TABLE paquetes_json ADD COLUMN empleado_id INT NULL"); } catch (Throwable $e) { }
                // Contar antes para reporte
                $cnt1 = $pdo->prepare("SELECT COUNT(*) c FROM paquetes WHERE empleado_id = ?");
                $cnt1->execute([$id]);
                $paquetes_asignados = (int)($cnt1->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

                $cnt2 = $pdo->prepare("SELECT COUNT(*) c FROM paquetes_json WHERE empleado_id = ?");
                try { $cnt2->execute([$id]); } catch (Throwable $e) { $paquetes_json_asignados = 0; }
                $paquetes_json_asignados = isset($paquetes_json_asignados) ? $paquetes_json_asignados : (int)($cnt2->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

                // Desasignar SOLO paquetes 'En almacén' en tabla paquetes
                $upd1 = $pdo->prepare("UPDATE paquetes 
                                        SET empleado_id = NULL 
                                        WHERE empleado_id = ? 
                                          AND (
                                            estado IS NULL OR 
                                            LOWER(estado) IN ('en almacen','en almacén','en_almacen','almacen')
                                          )");
                try { $upd1->execute([$id]); } catch (Throwable $e) { /* tabla puede no existir en algunos entornos */ }

                $upd2 = $pdo->prepare("UPDATE paquetes_json SET empleado_id = NULL WHERE empleado_id = ?");
                try { $upd2->execute([$id]); } catch (Throwable $e) { /* tabla opcional */ }

                // Eliminar usuario
                $del = $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND id != 1");
                $del->execute([$id]);
                if ($del->rowCount() === 0) { throw new Exception('No se pudo eliminar el usuario o no existe'); }

                // Limpieza defensiva: cualquier asignación con empleado inexistente y estado 'En almacén' -> NULL
                try {
                    $pdo->exec("UPDATE paquetes p 
                                LEFT JOIN usuarios u ON p.empleado_id = u.id 
                                SET p.empleado_id = NULL 
                                WHERE p.empleado_id IS NOT NULL 
                                  AND u.id IS NULL
                                  AND (
                                    p.estado IS NULL OR 
                                    LOWER(p.estado) IN ('en almacen','en almacén','en_almacen','almacen')
                                  )");
                } catch (Throwable $e) { /* ignorar si tabla/columna no existe */ }
                try {
                    $pdo->exec("UPDATE paquetes_json pj LEFT JOIN usuarios u ON pj.empleado_id = u.id SET pj.empleado_id = NULL WHERE pj.empleado_id IS NOT NULL AND u.id IS NULL");
                } catch (Throwable $e) { /* ignorar si tabla/columna no existe */ }

                $pdo->commit();
                echo json_encode([
                    'success' => 'Usuario eliminado y paquetes desasignados',
                    'desasignados' => [
                        'paquetes' => $paquetes_asignados,
                        'paquetes_json' => $paquetes_json_asignados
                    ]
                ]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            throw new Exception('Acción no válida');
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error en la base de datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
