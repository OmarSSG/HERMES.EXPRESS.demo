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
  if (!is_array($data)) { throw new Exception('Cuerpo JSON inválido'); }
  $empleado_id = (int)($data['empleado_id'] ?? 0);
  $rutas = $data['rutas'] ?? [];
  if ($empleado_id <= 0) throw new Exception('empleado_id inválido');
  if (!is_array($rutas) || count($rutas)===0) throw new Exception('rutas debe ser un arreglo no vacío');

  // Normalizar rutas
  $norm = [];
  foreach ($rutas as $r) { $t = trim((string)$r); if ($t!=='') $norm[$t]=true; }
  $rutas = array_keys($norm);
  if (!count($rutas)) throw new Exception('Sin rutas válidas');

  // Asegurar columnas requeridas
  try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN empleado_id INT NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE paquetes ADD COLUMN estado VARCHAR(50) NULL"); } catch (Throwable $e) {}
  // Asegurar columna fecha_finalizacion en historial
  try { $pdo->exec("ALTER TABLE asignaciones_paquetes ADD COLUMN fecha_finalizacion DATETIME NULL"); } catch (Throwable $e) {}

  // Seleccionar paquetes asignados al empleado en esas rutas
  $ph = implode(',', array_fill(0, count($rutas), '?'));
  $params = array_merge([$empleado_id], $rutas);
  $sqlSel = "SELECT id, distrito, estado FROM paquetes WHERE empleado_id = ? AND distrito IN ($ph)";
  $stmtSel = $pdo->prepare($sqlSel);
  $stmtSel->execute($params);
  $rows = $stmtSel->fetchAll(PDO::FETCH_ASSOC);
  if (!is_array($rows)) $rows = [];

  if (!count($rows)) {
    echo json_encode(['exito'=>true,'desasignados'=>0,'paquetes'=>[],'mensaje'=>'No había paquetes asignados en esas rutas']);
    exit;
  }

  // Desasignar: poner empleado_id = NULL y estado = 'En almacén'
  $ids = array_map(function($r){ return (int)$r['id']; }, $rows);
  $phIds = implode(',', array_fill(0, count($ids), '?'));
  $sqlUpd = "UPDATE paquetes SET empleado_id = NULL, estado = 'En almacén' WHERE id IN ($phIds)";
  $stmtUpd = $pdo->prepare($sqlUpd);
  $stmtUpd->execute($ids);

  // Marcar fecha_finalizacion en historial para estas asignaciones abiertas
  try {
    $ph2 = implode(',', array_fill(0, count($ids), '?'));
    $params2 = array_merge([$empleado_id], $ids);
    $sqlHist = "UPDATE asignaciones_paquetes SET fecha_finalizacion = NOW() WHERE empleado_id = ? AND paquete_id IN ($ph2) AND fecha_finalizacion IS NULL";
    $stmth = $pdo->prepare($sqlHist);
    $stmth->execute($params2);
  } catch (Throwable $e) { /* best effort */ }

  echo json_encode([
    'exito'=>true,
    'desasignados'=>count($ids),
    'paquetes'=>$ids,
    'mensaje'=>'Paquetes desasignados y movidos a En almacén'
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]);
}
