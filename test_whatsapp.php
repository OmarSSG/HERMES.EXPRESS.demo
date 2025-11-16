<?php
// Habilitar visualización de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Iniciando prueba de envío de WhatsApp...\n\n";

// Incluir configuración
require_once 'php/config.php';

// Verificar si las credenciales de Twilio están configuradas
if (empty($twilio_account_sid) || empty($twilio_auth_token) || empty($twilio_whatsapp_number)) {
    die("ERROR: Las credenciales de Twilio no están configuradas correctamente en config.php\n");
}

echo "Credenciales de Twilio encontradas.\n";

try {
    // Conectar a la base de datos
    $pdo = new PDO("mysql:host=$servidor;dbname=$base_datos;charset=utf8", $usuario_db, $clave_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Conexión a la base de datos exitosa.\n";
    
    // Inicializar el notificador
    require_once 'php/whatsapp_notifier.php';
    $whatsapp = new WhatsAppNotifier($pdo);
    
    // Número de prueba (reemplázalo con un número real con código de país)
    $testNumber = '51912112380'; // Número de prueba con código de país (51 para Perú)
    
    // Mensaje de prueba
    $message = "Prueba de mensaje desde HERMES.EXPRESS\n";
    $message .= "Paquete #TEST" . time() . "\n"; // Usamos timestamp para hacer único el ID
    $message .= "Cliente: Usuario de Prueba\n";
    $message .= "Estado: En camino\n";
    $message .= "Hora: " . date('Y-m-d H:i:s');
    
    echo "\n=== Detalles del mensaje ===\n";
    echo "Destinatario: $testNumber\n";
    echo "Mensaje:\n$message\n\n";
    
    echo "Enviando mensaje...\n";
    
    // Enviar el mensaje
    $startTime = microtime(true);
    $result = $whatsapp->sendMessage($testNumber, $message);
    $elapsed = round((microtime(true) - $startTime) * 1000); // Tiempo en milisegundos
    
    // Mostrar resultado
    echo "\n=== Resultado ===\n";
    echo "Tiempo de respuesta: {$elapsed}ms\n";
    
    if (isset($result['success']) && $result['success']) {
        echo "✅ Mensaje enviado exitosamente!\n";
        echo "ID del mensaje: " . ($result['message_id'] ?? 'N/A') . "\n";
    } else {
        echo "❌ Error al enviar el mensaje:\n";
        echo "Código de estado: " . ($result['status'] ?? 'N/A') . "\n";
        echo "Error: " . ($result['error'] ?? 'Error desconocido') . "\n";
        
        if (isset($result['response'])) {
            echo "\nRespuesta completa del servidor:\n";
            print_r($result['response']);
        }
    }
    
} catch (PDOException $e) {
    echo "\n❌ Error de conexión a la base de datos: " . $e->getMessage() . "\n";
    echo "Código de error: " . $e->getCode() . "\n";
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "En archivo: " . $e->getFile() . " (línea " . $e->getLine() . ")\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nPrueba completada.\n";