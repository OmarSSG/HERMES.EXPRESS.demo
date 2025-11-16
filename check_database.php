<?php
// Cargar autoloader de Composer
require_once __DIR__ . '/vendor/autoload.php';

// Cargar configuración
require_once __DIR__ . '/php/config.php';

try {
    // Conectar a la base de datos
    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=" . ($_ENV['DB_NAME'] ?? 'hermes_express'),
        $_ENV['DB_USER'] ?? 'root',
        $_ENV['DB_PASS'] ?? ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Conexión exitosa a la base de datos.\n";
    
    // Verificar si la tabla existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'whatsapp_notifications'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "La tabla 'whatsapp_notifications' existe.\n";
    } else {
        echo "La tabla 'whatsapp_notifications' NO existe. Creando tabla...\n";
        
        // Crear la tabla
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
        
        $pdo->exec($sql);
        echo "Tabla 'whatsapp_notifications' creada exitosamente.\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nVerificación completada.\n";
