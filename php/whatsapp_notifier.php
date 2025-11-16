<?php
/**
 * Módulo de notificaciones por WhatsApp para Hermes Express
 * Versión sin Composer
 */

// Incluir configuración
require_once __DIR__ . '/config.php';

// Clase para manejar notificaciones por WhatsApp
class WhatsAppNotifier {
    private $pdo;
    private $accountSid;
    private $authToken;
    private $fromNumber;
    private $apiUrl;
    
    public function __construct($pdo) {
        global $twilio_account_sid, $twilio_auth_token, $twilio_whatsapp_number;
        
        $this->pdo = $pdo;
        
        // Verificar credenciales
        if (empty($twilio_account_sid) || empty($twilio_auth_token) || empty($twilio_whatsapp_number)) {
            throw new Exception('Credenciales de Twilio no configuradas correctamente en config.php');
        }
        
        $this->accountSid = trim($twilio_account_sid);
        $this->authToken = trim($twilio_auth_token);
        $this->fromNumber = trim($twilio_whatsapp_number);
        $this->apiUrl = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";
        
        // Verificar si CURL está habilitado
        if (!function_exists('curl_version')) {
            throw new Exception('La extensión cURL no está habilitada en PHP');
        }
        
        // Crear tabla de seguimiento si no existe
        $this->createTrackingTable();
    }

    /**
     * Crea la tabla de seguimiento de notificaciones si no existe
     */
    private function createTrackingTable() {
        $sql = "CREATE TABLE IF NOT EXISTS `whatsapp_notifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `package_id` VARCHAR(50) NOT NULL,
            `phone_number` VARCHAR(20) NOT NULL,
            `customer_name` VARCHAR(100) NOT NULL,
            `status` ENUM('en_almacen', 'en_camino', 'entregado') NOT NULL,
            `message_sent` TEXT NOT NULL,
            `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `status_code` INT,
            `response` TEXT,
            `retry_count` INT DEFAULT 0,
            `next_retry` TIMESTAMP NULL,
            `is_success` BOOLEAN DEFAULT FALSE,
            `error_message` TEXT,
            INDEX `idx_package` (`package_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_phone` (`phone_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $this->pdo->exec($sql);
    }

    /**
     * Verifica si un número de teléfono es válido
     */
    private function isValidPhoneNumber($phone) {
        // Eliminar todo lo que no sea dígito
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        
        // Verificar longitud mínima (9 dígitos para números locales, 10-15 para internacionales)
        return strlen($cleaned) >= 9 && strlen($cleaned) <= 15;
    }

    /**
     * Formatea el número de teléfono para WhatsApp
     */
    private function formatPhoneNumber($phone) {
        // Eliminar todo lo que no sea dígito
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        
        // Si no tiene código de país, asumir Perú (+51)
        if (strlen($cleaned) === 9 && $cleaned[0] === '9') {
            return '51' . $cleaned; // Código de país Perú + número
        }
        
        return $cleaned;
    }

    /**
     * Registra una notificación en la base de datos
     * 
     * @param string $to Número de teléfono del destinatario
     * @param string $message Contenido del mensaje
     * @param string $status Estado del envío (enviado, error, pendiente)
     * @param string|null $error Mensaje de error (opcional)
     * @return bool True si se registró correctamente
     */
    private function logNotification($to, $message, $status, $error = null) {
        try {
            // Extraer el ID del paquete del mensaje (si está presente)
            preg_match('/Paquete #(\w+)/', $message, $matches);
            $packageId = $matches[1] ?? 'N/A';
            
            // Extraer el nombre del cliente (si está presente)
            preg_match('/Cliente: ([^\n]+)/', $message, $nameMatches);
            $customerName = $nameMatches[1] ?? 'Cliente';
            
            $stmt = $this->pdo->prepare(
                "INSERT INTO whatsapp_notifications 
                (package_id, phone_number, customer_name, status, message_sent, status_code, response, is_success, error_message) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $isSuccess = $status === 'enviado';
            $statusCode = $isSuccess ? 200 : 500;
            $response = $isSuccess ? 'Mensaje enviado correctamente' : $error;
            
            return $stmt->execute([
                $packageId,
                $to,
                $customerName,
                $status,
                $message,
                $statusCode,
                $response,
                $isSuccess ? 1 : 0,
                $isSuccess ? null : $error
            ]);
            
        } catch (PDOException $e) {
            error_log("Error al registrar notificación: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía un mensaje de WhatsApp usando la API de Twilio
     * 
     * @param string $to Número de teléfono del destinatario (con código de país, sin el +)
     * @param string $message Mensaje a enviar
     * @return array Resultado de la operación
     */
    public function sendMessage($to, $message) {
        // Validar credenciales
        if (empty($this->accountSid) || empty($this->authToken) || empty($this->fromNumber)) {
            $errorMsg = 'Credenciales de Twilio incompletas. Verifica config.php';
            error_log($errorMsg);
            return ['success' => false, 'error' => $errorMsg];
        }

        // Validar número de teléfono
        $to = preg_replace('/[^0-9]/', '', $to);
        if (empty($to) || strlen($to) < 9) {
            $errorMsg = 'Número de teléfono inválido';
            error_log($errorMsg . ': ' . $to);
            return ['success' => false, 'error' => $errorMsg];
        }
        
        $to = 'whatsapp:+' . ltrim($to, '+');
        
        // Inicializar cURL
        $ch = curl_init();
        if ($ch === false) {
            $errorMsg = 'No se pudo inicializar cURL';
            error_log($errorMsg);
            return ['success' => false, 'error' => $errorMsg];
        }
        
        try {
            // Configurar la URL de la API de Twilio
            $apiUrl = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";
            
            // Configurar los datos del mensaje
            $postData = [
                'To' => $to,
                'From' => 'whatsapp:' . $this->fromNumber,
                'Body' => $message
            ];
            
            // Configurar opciones de cURL
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($postData),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                CURLOPT_USERPWD => $this->accountSid . ':' . $this->authToken,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_VERBOSE => true
            ]);
            
            // Ejecutar la petición
            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            
            // Verificar errores de cURL
            if ($errno) {
                throw new Exception("cURL Error #$errno: $error");
            }
            
            // Decodificar la respuesta
            $responseData = json_decode($response, true);
            $success = ($statusCode >= 200 && $statusCode < 300);
            
            // Registrar en la base de datos
            $logResult = $this->logNotification(
                $to,
                $message,
                $success ? 'enviado' : 'error',
                $success ? null : ($error ?: $response)
            );
            
            if (!$logResult) {
                error_log("Advertencia: No se pudo registrar la notificación en la base de datos");
            }
            
            // Preparar respuesta
            $result = [
                'success' => $success,
                'status' => $statusCode,
                'message_id' => $responseData['sid'] ?? null,
                'response' => $responseData ?: $response,
                'error' => $success ? null : ($error ?: 'Error al enviar el mensaje')
            ];
            
            // Registrar error si es necesario
            if (!$success) {
                error_log("Error al enviar mensaje a $to. Código: $statusCode. Respuesta: " . print_r($result, true));
            }
            
            return $result;
            
        } catch (Exception $e) {
            $errorMsg = "Error al enviar mensaje: " . $e->getMessage();
            error_log($errorMsg);
            $this->logNotification($to, $message, 'error', $errorMsg);
            return ['success' => false, 'error' => $errorMsg];
            
        } finally {
            // Cerrar la conexión cURL
            if (is_resource($ch)) {
                curl_close($ch);
            }
        }
    }

    /**
     * Procesa un nuevo paquete detectado
     */
    public function processNewPackage($packageData) {
        $packageId = $packageData['id_paquete'] ?? '';
        $phone = $packageData['telefono'] ?? '';
        $customerName = $packageData['nombre_cliente'] ?? 'Cliente';
        
        if (empty($packageId) || empty($phone)) {
            throw new Exception("Datos de paquete incompletos");
        }
        
        // Verificar si ya existe una notificación para este paquete
        $stmt = $this->pdo->prepare(
            "SELECT * FROM whatsapp_notifications 
             WHERE package_id = ? 
             ORDER BY sent_at DESC 
             LIMIT 1"
        );
        $stmt->execute([$packageId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Si ya existe una notificación para este estado, no hacer nada
        if ($existing && $existing['status'] === 'en_almacen') {
            return false;
        }
        
        // Crear mensaje
        $message = "Hola {$customerName}, su paquete con ID {$packageId} ya se encuentra en nuestro almacén y pronto será despachado.";
        
        // Enviar mensaje
        try {
            $response = $this->sendMessage($phone, $message);
            
            // Registrar en la base de datos
            $stmt = $this->pdo->prepare(
                "INSERT INTO whatsapp_notifications 
                 (package_id, phone_number, customer_name, status, message_sent, 
                  status_code, response, is_success) 
                 VALUES (?, ?, ?, 'en_almacen', ?, ?, ?, ?)"
            );
            
            $stmt->execute([
                $packageId,
                $phone,
                $customerName,
                $message,
                $response['status'] ?? 0,
                json_encode($response),
                $response['success'] ?? false
            ]);
            
            return $response['success'] ?? false;
            
        } catch (Exception $e) {
            // Registrar error
            error_log("Error al enviar notificación WhatsApp: " . $e->getMessage());
            
            $stmt = $this->pdo->prepare(
                "INSERT INTO whatsapp_notifications 
                 (package_id, phone_number, customer_name, status, message_sent, 
                  is_success, error_message) 
                 VALUES (?, ?, ?, 'en_almacen', ?, FALSE, ?)"
            );
            
            $stmt->execute([
                $packageId,
                $phone,
                $customerName,
                $message,
                $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Actualiza el estado de un paquete
     */
    public function updatePackageStatus($packageId, $newStatus) {
        $validStatuses = ['en_camino', 'entregado'];
        
        if (!in_array($newStatus, $validStatuses)) {
            throw new Exception("Estado no válido: " . $newStatus);
        }
        
        // Obtener datos del paquete
        $stmt = $this->pdo->prepare(
            "SELECT * FROM whatsapp_notifications 
             WHERE package_id = ? 
             ORDER BY sent_at DESC 
             LIMIT 1"
        );
        $stmt->execute([$packageId]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$package) {
            throw new Exception("Paquete no encontrado: " . $packageId);
        }
        
        // Verificar si ya existe una notificación para este estado
        if ($package['status'] === $newStatus) {
            return false; // Ya existe una notificación para este estado
        }
        
        // Crear mensaje según el estado
        $message = '';
        if ($newStatus === 'en_camino') {
            $message = "Buen día {$package['customer_name']}, su paquete ID {$packageId} ha salido del almacén y está en camino hacia su domicilio.";
        } elseif ($newStatus === 'entregado') {
            $message = "Hola {$package['customer_name']}, nos complace informarle que su paquete ID {$packageId} ha sido recibido correctamente. ¡Gracias por su confianza!";
        }
        
        // Enviar mensaje
        try {
            $response = $this->sendMessage($package['phone_number'], $message);
            
            // Registrar en la base de datos
            $stmt = $this->pdo->prepare(
                "INSERT INTO whatsapp_notifications 
                 (package_id, phone_number, customer_name, status, message_sent, 
                  status_code, response, is_success) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $stmt->execute([
                $packageId,
                $package['phone_number'],
                $package['customer_name'],
                $newStatus,
                $message,
                $response['status'] ?? 0,
                json_encode($response),
                $response['success'] ?? false
            ]);
            
            return $response['success'] ?? false;
            
        } catch (Exception $e) {
            // Registrar error
            error_log("Error al actualizar estado de paquete: " . $e->getMessage());
            
            $stmt = $this->pdo->prepare(
                "INSERT INTO whatsapp_notifications 
                 (package_id, phone_number, customer_name, status, message_sent, 
                  is_success, error_message) 
                 VALUES (?, ?, ?, ?, ?, FALSE, ?)"
            );
            
            $stmt->execute([
                $packageId,
                $package['phone_number'],
                $package['customer_name'],
                $newStatus,
                $message,
                $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Reintenta el envío de notificaciones fallidas
     */
    public function retryFailedNotifications($maxRetries = 3) {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM whatsapp_notifications 
             WHERE is_success = FALSE 
             AND (retry_count < ? OR retry_count IS NULL)
             AND (next_retry IS NULL OR next_retry <= NOW())"
        );
        $stmt->execute([$maxRetries]);
        $failed = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        
        foreach ($failed as $notification) {
            try {
                $response = $this->sendMessage(
                    $notification['phone_number'], 
                    $notification['message_sent']
                );
                
                // Actualizar registro existente
                $updateStmt = $this->pdo->prepare(
                    "UPDATE whatsapp_notifications 
                     SET is_success = 1, 
                         status_code = ?,
                         response = ?,
                         sent_at = NOW(),
                         retry_count = retry_count + 1
                     WHERE id = ?"
                );
                
                $updateStmt->execute([
                    $response['status'] ?? 0,
                    json_encode($response),
                    $notification['id']
                ]);
                
                $results[] = [
                    'id' => $notification['id'],
                    'success' => true,
                    'message' => 'Notificación reenviada correctamente'
                ];
                
            } catch (Exception $e) {
                // Calcular siguiente intento (exponencial backoff)
                $retryCount = ($notification['retry_count'] ?? 0) + 1;
                $nextRetry = date('Y-m-d H:i:s', strtotime("+{$retryCount} minutes"));
                
                $updateStmt = $this->pdo->prepare(
                    "UPDATE whatsapp_notifications 
                     SET retry_count = ?,
                         next_retry = ?,
                         error_message = ?
                     WHERE id = ?"
                );
                
                $updateStmt->execute([
                    $retryCount,
                    $nextRetry,
                    $e->getMessage(),
                    $notification['id']
                ]);
                
                $results[] = [
                    'id' => $notification['id'],
                    'success' => false,
                    'message' => 'Error al reenviar: ' . $e->getMessage(),
                    'next_retry' => $nextRetry
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Genera un informe diario de notificaciones
     */
    public function generateDailyReport($date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        $startDate = $date . ' 00:00:00';
        $endDate = $date . ' 23:59:59';
        
        // Obtener estadísticas generales
        $stmt = $this->pdo->prepare(
            "SELECT 
                status,
                COUNT(*) as total,
                SUM(CASE WHEN is_success = 1 THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN is_success = 0 THEN 1 ELSE 0 END) as failed
             FROM whatsapp_notifications 
             WHERE sent_at BETWEEN ? AND ?
             GROUP BY status"
        );
        $stmt->execute([$startDate, $endDate]);
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener detalles de los fallos
        $stmt = $this->pdo->prepare(
            "SELECT * FROM whatsapp_notifications 
             WHERE is_success = 0 
             AND sent_at BETWEEN ? AND ?
             ORDER BY sent_at DESC"
        );
        $stmt->execute([$startDate, $endDate]);
        $failures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'date' => $date,
            'stats' => $stats,
            'failures' => $failures,
            'total_notifications' => array_sum(array_column($stats, 'total')),
            'success_rate' => $this->calculateSuccessRate($stats)
        ];
    }
    
    /**
     * Calcula la tasa de éxito de las notificaciones
     */
    private function calculateSuccessRate($stats) {
        $total = 0;
        $success = 0;
        
        foreach ($stats as $stat) {
            $total += $stat['total'];
            $success += $stat['success'];
        }
        
        return $total > 0 ? round(($success / $total) * 100, 2) : 0;
    }
}

// Uso básico (ejemplo)
/*
try {
    $pdo = new PDO("mysql:host=localhost;dbname=hermes_express", "root", "");
    $notifier = new WhatsAppNotifier($pdo);
    
    // Procesar nuevo paquete
    $notifier->processNewPackage([
        'id_paquete' => 'ABC123',
        'telefono' => '912345678',
        'nombre_cliente' => 'Juan Pérez'
    ]);
    
    // Actualizar estado de paquete
    $notifier->updatePackageStatus('ABC123', 'en_camino');
    
    // Reintentar notificaciones fallidas
    $notifier->retryFailedNotifications();
    
    // Generar informe diario
    $report = $notifier->generateDailyReport();
    print_r($report);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
*/
?>
