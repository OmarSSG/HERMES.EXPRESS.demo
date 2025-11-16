<?php
// Configuración de base de datos
$servidor = "localhost";
$usuario_db = "root";
$clave_db = "";
$base_datos = "hermes_express";

try {
    $pdo = new PDO("mysql:host=$servidor;dbname=$base_datos;charset=utf8", $usuario_db, $clave_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Obtener estructura de la tabla paquetes
    $stmt = $pdo->query("SHOW COLUMNS FROM paquetes");
    echo "=== ESTRUCTURA DE LA TABLA paquetes ===\n";
    while ($columna = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- {$columna['Field']} ({$columna['Type']})\n";
    }
    
    // Obtener estructura de la tabla clientes
    $stmt = $pdo->query("SHOW COLUMNS FROM clientes");
    echo "\n=== ESTRUCTURA DE LA TABLA clientes ===\n";
    while ($columna = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- {$columna['Field']} ({$columna['Type']})\n";
    }
    
    // Verificar si hay datos en paquetes
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM paquetes");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\n=== ESTADÍSTICAS ===\n";
    echo "Total de paquetes: " . $total['total'] . "\n";
    
    // Verificar si hay datos en clientes
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clientes");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total de clientes: " . $total['total'] . "\n";
    
} catch(PDOException $e) {
    echo "Error de conexión: " . $e->getMessage();
}
?>
