<?php
// Incluir configuración y verificar sesión
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/verificar_sesion.php';
require_once __DIR__ . '/whatsapp_notifier.php';

header('Content-Type: application/json; charset=utf-8');

$response = ['exito' => false, 'mensaje' => ''];

try {
    // Verificar que sea una petición POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    // Obtener datos del POST
    $idPaquete = $_POST['id_paquete'] ?? '';
    $telefono = $_POST['telefono'] ?? '';
    $cliente = $_POST['cliente'] ?? '';
    $estado = $_POST['estado'] ?? '';
    $mensajePersonalizado = $_POST['mensaje_personalizado'] ?? '';

    // Validar datos
    if (empty($idPaquete) || empty($telefono) || empty($cliente) || empty($estado)) {
        throw new Exception('Faltan datos obligatorios');
    }

    // Conectar a la base de datos
    $pdo = new PDO("mysql:host=$servidor;dbname=$base_datos;charset=utf8", $usuario_db, $clave_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Inicializar notificador de WhatsApp
    $whatsapp = new WhatsAppNotifier($pdo);

    // Construir el mensaje
    if (empty($mensajePersonalizado)) {
        $mensaje = "*HERMES EXPRESS - ACTUALIZACIÓN DE PAQUETE*\n\n";
        $mensaje .= "Hola $cliente,\n";
        $mensaje .= "El estado de tu paquete ha sido actualizado.\n\n";
        $mensaje .= "📦 *ID de Paquete:* $idPaquete\n";
        $mensaje .= "🔄 *Nuevo Estado:* $estado\n\n";
        $mensaje .= "Gracias por confiar en nosotros.\n";
        $mensaje .= "Para más información, contáctanos.\n\n";
        $mensaje .= "_Este es un mensaje automático, por favor no responder._";
    } else {
        $mensaje = $mensajePersonalizado;
    }

    // Enviar mensaje
    $resultado = $whatsapp->sendMessage($telefono, $mensaje);

    if ($resultado['success']) {
        $response['exito'] = true;
        $response['mensaje'] = 'Notificación enviada correctamente';
        $response['message_id'] = $resultado['message_id'] ?? null;
    } else {
        throw new Exception($resultado['error'] ?? 'Error al enviar la notificación');
    }

} catch (Exception $e) {
    $response['mensaje'] = $e->getMessage();
    if (!headers_sent()) {
        http_response_code(400);
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
