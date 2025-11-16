<?php
// php/db_config.php
// Configuración de conexión MySQL
// Ajusta estas credenciales según tu entorno XAMPP

// Cargar .env si está disponible para poblar getenv()
if (file_exists(__DIR__ . '/../vendor/autoload.php')) { require_once __DIR__ . '/../vendor/autoload.php'; }
if (class_exists('Dotenv\\Dotenv')) { try { Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad(); } catch (Throwable $e) { } }

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'hermes_express');

define('PAQUETES_TABLE', 'paquetes_json');
function db_connect(): mysqli {
    $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        throw new Exception('Error de conexión MySQL: ' . $mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}
