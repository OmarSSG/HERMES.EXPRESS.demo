<?php
// php/asignaciones.php
@ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_config.php';

function db(): mysqli { return db_connect(); }

function ensure_tables(mysqli $db) {
  $tabla_paquetes = $db->real_escape_string(PAQUETES_TABLE);
  // Tabla de asignaciones
  $db->query(
    "CREATE TABLE IF NOT EXISTS `asignaciones_paquetes` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `paquete_id` INT NOT NULL,
      `empleado_id` INT NOT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(`id`),
      UNIQUE KEY `uniq_paquete` (`paquete_id`),
      KEY `idx_empleado` (`empleado_id`),
      CONSTRAINT `fk_asig_paquete_json` FOREIGN KEY (`paquete_id`) REFERENCES `{$tabla_paquetes}`(`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_asig_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
  );
}

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

try {
  $mysqli = db();
  ensure_tables($mysqli);

  switch ($accion) {
    case 'empleados': {
      $res = $mysqli->query("SELECT id, nombre FROM usuarios WHERE tipo='empleado' AND activo=1 ORDER BY nombre");
      $rows = [];
      if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } $res->free(); }
      echo json_encode(['exito'=>true,'datos'=>$rows], JSON_UNESCAPED_UNICODE);
      break;
    }
    case 'distritos': {
      $tabla = $mysqli->real_escape_string(PAQUETES_TABLE);
      $sql = "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.distrito')) AS distrito FROM `{$tabla}` WHERE JSON_EXTRACT(data, '$.distrito') IS NOT NULL ORDER BY distrito";
      $res = $mysqli->query($sql);
      $rows = [];
      if ($res) { while ($r = $res->fetch_assoc()) { if ($r['distrito'] !== null && $r['distrito'] !== '') $rows[] = $r['distrito']; } $res->free(); }
      echo json_encode(['exito'=>true,'datos'=>$rows], JSON_UNESCAPED_UNICODE);
      break;
    }
    case 'paquetes_sin_asignar': {
      $tabla = $mysqli->real_escape_string(PAQUETES_TABLE);
      $limit = isset($_GET['limit']) ? max(1, min(2000, (int)$_GET['limit'])) : 500;
      $sql = "SELECT p.id, p.data FROM `{$tabla}` p LEFT JOIN asignaciones_paquetes a ON a.paquete_id = p.id WHERE a.id IS NULL ORDER BY p.id DESC LIMIT {$limit}";
      $res = $mysqli->query($sql);
      $rows = [];
      if ($res) {
        while ($r = $res->fetch_assoc()) {
          $obj = json_decode($r['data'], true);
          if (is_array($obj)) { $obj['_id'] = (int)$r['id']; $rows[] = $obj; }
        }
        $res->free();
      }
      echo json_encode(['exito'=>true,'datos'=>$rows], JSON_UNESCAPED_UNICODE);
      break;
    }
    case 'asignar_por_distrito': {
      $empleado_id = (int)($_POST['empleado_id'] ?? 0);
      $distritos = $_POST['distritos'] ?? [];
      if ($empleado_id <= 0 || !is_array($distritos) || !count($distritos)) {
        echo json_encode(['exito'=>false,'mensaje'=>'Parámetros inválidos']);
        break;
      }
      $tabla = $mysqli->real_escape_string(PAQUETES_TABLE);
      // Preparar IN (...) seguro
      $placeholders = implode(',', array_fill(0, count($distritos), '?'));
      $types = str_repeat('s', count($distritos));
      $stmt = $mysqli->prepare("SELECT id FROM `{$tabla}` WHERE JSON_UNQUOTE(JSON_EXTRACT(data, '$.distrito')) IN ($placeholders)");
      $stmt->bind_param($types, ...$distritos);
      $stmt->execute();
      $result = $stmt->get_result();
      $ids = [];
      while ($row = $result->fetch_assoc()) { $ids[] = (int)$row['id']; }
      $stmt->close();

      if (!count($ids)) { echo json_encode(['exito'=>true,'asignados'=>0]); break; }

      // Filtrar ya asignados
      $place = implode(',', array_fill(0, count($ids), '?'));
      $types2 = str_repeat('i', count($ids));
      $stmt2 = $mysqli->prepare("SELECT paquete_id FROM asignaciones_paquetes WHERE paquete_id IN ($place)");
      $stmt2->bind_param($types2, ...$ids);
      $stmt2->execute();
      $res2 = $stmt2->get_result();
      $ya = [];
      while ($r = $res2->fetch_assoc()) { $ya[(int)$r['paquete_id']] = true; }
      $stmt2->close();

      $nuevos = array_values(array_filter($ids, fn($id) => !isset($ya[$id])));
      if (!count($nuevos)) { echo json_encode(['exito'=>true,'asignados'=>0]); break; }

      // Insertar asignaciones
      $stmt3 = $mysqli->prepare("INSERT INTO asignaciones_paquetes (paquete_id, empleado_id) VALUES (?, ?)");
      foreach ($nuevos as $pid) { $pid = (int)$pid; $stmt3->bind_param('ii', $pid, $empleado_id); $stmt3->execute(); }
      $stmt3->close();

      echo json_encode(['exito'=>true,'asignados'=>count($nuevos)]);
      break;
    }
    case 'listar_asignaciones': {
      $tabla = $mysqli->real_escape_string(PAQUETES_TABLE);
      $limit = isset($_GET['limit']) ? max(1, min(2000, (int)$_GET['limit'])) : 200;
      $sql = "SELECT a.id, a.paquete_id, a.empleado_id, a.created_at, u.nombre AS empleado, p.data 
              FROM asignaciones_paquetes a 
              JOIN usuarios u ON u.id = a.empleado_id 
              JOIN `{$tabla}` p ON p.id = a.paquete_id 
              ORDER BY a.id DESC LIMIT {$limit}";
      $res = $mysqli->query($sql);
      $rows = [];
      if ($res) { while ($r = $res->fetch_assoc()) { $obj = json_decode($r['data'], true) ?: []; $obj['_id'] = (int)$r['paquete_id']; $obj['_empleado'] = $r['empleado']; $obj['_fecha_asignacion'] = $r['created_at']; $rows[] = $obj; } $res->free(); }
      echo json_encode(['exito'=>true,'datos'=>$rows], JSON_UNESCAPED_UNICODE);
      break;
    }
    default:
      echo json_encode(['exito'=>false,'mensaje'=>'Acción no válida']);
  }
} catch (Throwable $e) {
  echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]);
}
