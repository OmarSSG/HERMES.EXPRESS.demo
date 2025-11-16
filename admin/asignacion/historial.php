<?php
require_once __DIR__ . '/../../php/config.php';
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

try {
  if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['tipo'], ['admin','asistente'])) {
    http_response_code(403);
    echo json_encode(['exito'=>false,'mensaje'=>'Acceso no autorizado']);
    exit;
  }

  // Soportar /historial/:empleado_id vía PATH_INFO o query empleado_id
  $empleado_id = 0;
  if (!empty($_GET['empleado_id'])) {
    $empleado_id = (int)$_GET['empleado_id'];
  } else if (!empty($_SERVER['PATH_INFO'])) {
    $parts = explode('/', trim($_SERVER['PATH_INFO'],'/'));
    if (isset($parts[0]) && ctype_digit($parts[0])) $empleado_id = (int)$parts[0];
  }
  if ($empleado_id <= 0) throw new Exception('empleado_id requerido');

  // Asegurar tablas/columnas presentes
  $pdo->exec("CREATE TABLE IF NOT EXISTS asignaciones_paquetes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paquete_id INT NOT NULL,
    empleado_id INT NOT NULL,
    ruta VARCHAR(120) NULL,
    estado_anterior VARCHAR(50) NULL,
    fecha_asignacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_finalizacion DATETIME NULL,
    INDEX idx_emp (empleado_id),
    INDEX idx_pk (paquete_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  try { $pdo->exec("ALTER TABLE asignaciones_paquetes ADD COLUMN fecha_finalizacion DATETIME NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE asignaciones_paquetes ADD COLUMN ruta VARCHAR(120) NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE asignaciones_paquetes ADD COLUMN estado_anterior VARCHAR(50) NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE asignaciones_paquetes ADD COLUMN fecha_asignacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}

  // Traer historial: join con paquetes para estado actual y codigo
  $sql = "SELECT a.id,
                 a.paquete_id,
                 a.empleado_id,
                 a.ruta,
                 a.estado_anterior,
                 a.fecha_asignacion,
                 a.fecha_finalizacion,
                 p.codigo AS paquete_codigo,
                 p.estado AS estado_actual
          FROM asignaciones_paquetes a
          LEFT JOIN paquetes p ON p.id = a.paquete_id
          WHERE a.empleado_id = ?
          ORDER BY a.fecha_asignacion DESC, a.id DESC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$empleado_id]);
  $hist = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['exito'=>true, 'empleado_id'=>$empleado_id, 'historial'=>$hist]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]);
}
