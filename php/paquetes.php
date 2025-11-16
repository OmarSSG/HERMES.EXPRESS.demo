<?php
// php/paquetes.php
// Lista los paquetes desde MySQL (tabla con columna JSON `data`)
@ini_set('display_errors', '0');
error_reporting(0);
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=utf-8');

$response = [ 'exito' => false, 'mensaje' => '', 'total' => 0, 'headers' => [], 'datos' => [] ];

try {
    $mysqli = db_connect();

    // Asegurar que la tabla exista (evita error si aún no se ha importado nada)
    $tabla = $mysqli->real_escape_string(PAQUETES_TABLE);
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `{$tabla}` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `data` JSON NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );

    // Parámetros opcionales
    $limit = isset($_GET['limit']) ? max(1, min(5000, (int)$_GET['limit'])) : 1000;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    // Total
    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `" . $mysqli->real_escape_string(PAQUETES_TABLE) . "`");
    $row = $res ? $res->fetch_assoc() : ['c' => 0];
    $response['total'] = (int)$row['c'];
    if ($res) { $res->free(); }

    // Datos (más recientes primero)
    $sql = "SELECT `data` FROM `" . $mysqli->real_escape_string(PAQUETES_TABLE) . "` ORDER BY `id` DESC LIMIT $limit OFFSET $offset";
    $res = $mysqli->query($sql);
    if ($res) {
        $headers = [];
        while ($r = $res->fetch_assoc()) {
            $obj = json_decode($r['data'], true);
            if (is_array($obj)) {
                $response['datos'][] = $obj;
                foreach ($obj as $k => $_) { $headers[$k] = true; }
            }
        }
        $res->free();
        $response['headers'] = array_keys($headers);
    }

    $response['exito'] = true;
} catch (Throwable $e) {
    $response['exito'] = false;
    $response['mensaje'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
