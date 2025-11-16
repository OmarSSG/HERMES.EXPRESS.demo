<?php
@ini_set('display_errors', '0');
error_reporting(0);
ob_start();

$logFile = __DIR__ . '/run_selenium.log';
function log_line($msg) {
    global $logFile;
    @file_put_contents($logFile, '['.date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND);
}

$response = ['exito'=>false,'mensaje'=>'','salida'=>'','python'=>''];

try {
    $root = realpath(__DIR__ . '/..');
    if ($root === false) throw new Exception('No se pudo resolver la ruta raíz');

    $script = $root . DIRECTORY_SEPARATOR . 'selenium_utils.py';
    if (!file_exists($script)) throw new Exception('No se encontró selenium_utils.py');

    if (!function_exists('exec')) throw new Exception('exec() deshabilitado en PHP');

    // Detectar Python (similar a import_paquetes.php)
    $candidatos = [
        'C:\\Users\\JULIO DME\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
        'C:\\Users\\JULIO DME\\AppData\\Local\\Programs\\Python\\Python312\\python.exe',
        'C:\\Python313\\python.exe',
        'C:\\Python312\\python.exe',
        'python',
        'py -3',
        'py'
    ];

    $salida = [];
    $codigo = 0;
    $raw = '';
    $elegido = '';

    foreach ($candidatos as $cand) {
        if (preg_match('/\\\\|\//', $cand)) {
            if (!file_exists($cand)) { log_line("SKIP inexistente: $cand"); continue; }
            $cmd = '"' . $cand . '" ' . '"' . $script . '" 2>&1';
        } else {
            $cmd = $cand . ' ' . '"' . $script . '" 2>&1';
        }
        $salida = [];
        $codigo = 0;
        log_line("PROBANDO: $cmd");
        exec($cmd, $salida, $codigo);
        $raw = implode("\n", $salida);
        log_line("CODE: $codigo\nOUT:\n$raw");
        if ($codigo === 0) { $elegido = $cand; break; }
    }

    $response['python'] = $elegido ?: 'no_detectado';
    $response['salida'] = $raw;

    if ($codigo === 0) {
        $response['exito'] = true;
        $response['mensaje'] = 'Ejecución completada. Revisa downloads/';
    } else {
        $response['exito'] = false;
        $response['mensaje'] = 'El script finalizó con código ' . $codigo . ' (Python: ' . ($elegido ?: 'no_detectado') . ')';
    }
} catch (Throwable $e) {
    $response['exito'] = false;
    $response['mensaje'] = 'Error al ejecutar: ' . $e->getMessage();
    log_line('ERROR: ' . $e->getMessage());
}

$body = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
header('Content-Type: application/json; charset=utf-8');
@ob_end_clean();
echo $body;
/* 
$response = [
    'exito' => false,
    'mensaje' => '',
    'salida' => ''
];

try {
    $root = realpath(__DIR__ . '/..');
    if ($root === false) {
        throw new Exception('No se pudo resolver la ruta raíz del proyecto');
    }

    $script = $root . DIRECTORY_SEPARATOR . 'selenium_utils.py';
    if (!file_exists($script)) {
        throw new Exception('No se encontró selenium_utils.py en la raíz del proyecto');
    }

    if (!function_exists('exec')) {
        throw new Exception('exec() deshabilitado en PHP');
    }

    // Ruta del intérprete (ajusta si es necesario)
    $python = 'python';
    // $python = 'C:\\Users\\TU_USUARIO\\AppData\\Local\\Programs\\Python\\Python312\\python.exe';

    // Ejecutar el script
    $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' 2>&1';
    $salida = [];
    $codigo = 0;
    exec($cmd, $salida, $codigo);

    $response['salida'] = implode("\n", $salida);
    if ($codigo === 0) {
        $response['exito'] = true;
        $response['mensaje'] = 'Ejecución completada. Revisa downloads/';
    } else {
        $response['exito'] = false;
        $response['mensaje'] = 'El script finalizó con código ' . $codigo;
    }
} catch (Throwable $e) {
    $response['exito'] = false;
    $response['mensaje'] = 'Error al ejecutar: ' . $e->getMessage();
}

// ÚNICA salida JSON limpia
$body = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
header('Content-Type: application/json; charset=utf-8');
header('Content-Length: ' . strlen($body));
@ob_end_clean(); // limpia cualquier cosa previa
echo $body;
*/