<?php
// Iniciar sesión para verificar permisos
session_start();

// Verificar si el usuario está autenticado y es administrador
if (!isset($_SESSION['usuario']) || $_SESSION['tipo'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['exito' => false, 'mensaje' => 'Acceso no autorizado']);
    exit;
}

// Obtener los datos del cuerpo de la petición
$json = file_get_contents('php://input');
$datos = json_decode($json, true);

// Validar los datos recibidos
if (!isset($datos['fechaInicio']) || !isset($datos['fechaFin']) || !isset($datos['usuario']) || !isset($datos['contrasena'])) {
    http_response_code(400);
    echo json_encode(['exito' => false, 'mensaje' => 'Datos incompletos']);
    exit;
}

// Validar formato de fechas
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datos['fechaInicio']) || 
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datos['fechaFin'])) {
    http_response_code(400);
    echo json_encode(['exito' => false, 'mensaje' => 'Formato de fecha inválido. Use YYYY-MM-DD']);
    exit;
}

// Configurar el comando para ejecutar el script de Python (selenium_utils.py está en la raíz del proyecto)
$pythonScript = __DIR__ . '/../selenium_utils.py';
$command = sprintf(
    'python "%s" --fecha-inicio "%s" --fecha-fin "%s" --usuario "%s" --contrasena "%s"',
    $pythonScript,
    escapeshellarg($datos['fechaInicio']),
    escapeshellarg($datos['fechaFin']),
    escapeshellarg($datos['usuario']),
    escapeshellarg($datos['contrasena'])
);

// Ejecutar el comando y capturar la salida
exec($command . ' 2>&1', $output, $returnVar);

// Procesar la salida
$resultado = [
    'exito' => $returnVar === 0,
    'salida' => $output,
    'comando' => $command
];

// Intentar extraer información de la salida
$resumen = [
    'registros' => 0,
    'paquetes' => 0,
    'errores' => 0
];

foreach ($output as $linea) {
    if (preg_match('/Se procesaron (\d+) registros/', $linea, $matches)) {
        $resumen['registros'] = (int)$matches[1];
    } elseif (preg_match('/Total de paquetes guardados: (\d+)/', $linea, $matches)) {
        $resumen['paquetes'] = (int)$matches[1];
    } elseif (stripos($linea, 'error') !== false) {
        $resumen['errores']++;
    }
}

$resultado['resumen'] = $resumen;

// Si hubo un error en la ejecución
if ($returnVar !== 0) {
    $resultado['mensaje'] = 'Error al ejecutar el script de extracción';
    if (!empty($output)) {
        $resultado['error_detalle'] = end($output);
    }
} else {
    $resultado['mensaje'] = 'Extracción completada correctamente';
}

// Devolver la respuesta en formato JSON
header('Content-Type: application/json');
echo json_encode($resultado);
?>
