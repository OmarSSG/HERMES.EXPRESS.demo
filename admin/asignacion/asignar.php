<?php
require_once __DIR__ . '/../../php/config.php';
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

try {
  if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['tipo'], ['admin','asistente'])) {
    http_response_code(403);
    echo json_encode(['exito'=>false,'mensaje'=>'Acceso no autorizado']);
    exit;
  }

  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    throw new Exception('Cuerpo JSON inválido');
  }
  $empleado_id = (int)($data['empleado_id'] ?? 0);
  $rutas = $data['rutas'] ?? [];
  if ($empleado_id <= 0) throw new Exception('empleado_id inválido');
  if (!is_array($rutas) || count($rutas)===0) throw new Exception('rutas debe ser un arreglo no vacío');

  // Normalizar rutas seleccionadas (distritos/subrutas)
  $norm = [];
  foreach ($rutas as $r) {
    $t = trim((string)$r);
    if ($t !== '') $norm[$t] = true;
  }
  $rutas = array_keys($norm);
  if (!count($rutas)) throw new Exception('Sin rutas válidas');

  // Validar empleado existe y activo
  $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id=? AND activo=1");
  $stmt->execute([$empleado_id]);
  if (!$stmt->fetch(PDO::FETCH_ASSOC)) throw new Exception('Empleado no válido o inactivo');

  // Asegurar columnas/tabla necesarias
  try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN empleado_id INT NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN estado VARCHAR(50) NULL"); } catch (Throwable $e) {}
  $pdo->exec("CREATE TABLE IF NOT EXISTS asignaciones_paquetes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paquete_id INT NOT NULL,
    empleado_id INT NOT NULL,
    ruta VARCHAR(120) NULL,
    estado_anterior VARCHAR(50) NULL,
    fecha_asignacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_paquete (paquete_id),
    INDEX idx_empleado (empleado_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  // Estados que consideramos como "En almacén"
  $estadosEnAlmacen = ['en almacen','en almacén','en_almacen','almacen','pendiente', null, ''];

  // Seleccionar paquetes candidatos por distrito y estado en almacén
  // Normalización simple para comparación case-insensitive
  $placeholders = implode(',', array_fill(0, count($rutas), '?'));
  $sqlSel = "SELECT id, distrito, estado FROM paquetes
             WHERE (distrito IN ($placeholders))
               AND (estado IS NULL OR LOWER(estado) IN ('en almacen','en almacén','en_almacen','almacen','pendiente'))";
  $stmtSel = $pdo->prepare($sqlSel);
  $stmtSel->execute($rutas);
  $rows = $stmtSel->fetchAll(PDO::FETCH_ASSOC);
  if (!is_array($rows)) $rows = [];
  if (!count($rows)) {
    echo json_encode(['exito'=>true,'asignados'=>0,'paquetes'=>[]]);
    exit;
  }

  // Actualizar en bloque por IDs
  $ids = array_map(function($r){ return (int)$r['id']; }, $rows);
  $phIds = implode(',', array_fill(0, count($ids), '?'));
  $paramsUpd = array_merge([$empleado_id], $ids);
  $sqlUpd = "UPDATE paquetes SET empleado_id = ?, estado = 'Asignado' WHERE id IN ($phIds)";
  $stmtUpd = $pdo->prepare($sqlUpd);
  $stmtUpd->execute($paramsUpd);

  // Registrar asignaciones
  $ins = $pdo->prepare("INSERT INTO asignaciones_paquetes (paquete_id, empleado_id, ruta, estado_anterior) VALUES (?,?,?,?)");
  foreach ($rows as $r) {
    $ins->execute([(int)$r['id'], $empleado_id, (string)($r['distrito'] ?? ''), (string)($r['estado'] ?? '')]);
  }

  echo json_encode([
    'exito'=>true,
    'asignados'=>count($ids),
    'paquetes'=>$ids
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]);
}
