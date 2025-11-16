<?php
// Configuración de base de datos
$servidor = "localhost";
$usuario_db = "root";
$clave_db = "";
$base_datos = "hermes_express";

header('Content-Type: text/plain; charset=utf-8');

echo "=== Verificación de la base de datos ===\n\n";

// Verificar conexión a la base de datos
try {
    $pdo = new PDO("mysql:host=$servidor;dbname=$base_datos;charset=utf8", $usuario_db, $clave_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conexión a la base de datos exitosa\n";
} catch (PDOException $e) {
    die("❌ Error de conexión: " . $e->getMessage() . "\n");
}

// Función para verificar si una tabla existe
function tableExists($pdo, $tableName) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE '$tableName'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Función para verificar si una columna existe en una tabla
function columnExists($pdo, $tableName, $columnName) {
    try {
        $result = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Verificar tablas requeridas
$requiredTables = ['usuarios', 'rutas', 'vehiculos', 'paquetes'];
$allTablesExist = true;

echo "\n=== Verificando tablas requeridas ===\n";
foreach ($requiredTables as $table) {
    if (tableExists($pdo, $table)) {
        echo "✅ Tabla '$table' existe\n";
    } else {
        echo "❌ Tabla '$table' NO existe\n";
        $allTablesExist = false;
    }
}

// Verificar columnas requeridas en la tabla usuarios
if (tableExists($pdo, 'usuarios')) {
    echo "\n=== Verificando columnas en la tabla 'usuarios' ===\n";
    $requiredColumns = [
        'id', 'usuario', 'clave', 'nombre', 'email', 'tipo', 'activo', 'fecha_creacion'
    ];
    
    foreach ($requiredColumns as $column) {
        if (columnExists($pdo, 'usuarios', $column)) {
            echo "✅ Columna 'usuarios.$column' existe\n";
        } else {
            echo "❌ Columna 'usuarios.$column' NO existe\n";
            $allTablesExist = false;
        }
    }
}

// Verificar si hay usuarios en la base de datos
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM usuarios");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "\n=== Conteo de usuarios ===\n";
    echo "Usuarios en la base de datos: $count\n";
    
    if ($count > 0) {
        $stmt = $pdo->query("SELECT id, usuario, tipo, activo FROM usuarios LIMIT 5");
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nPrimeros 5 usuarios:\n";
        foreach ($usuarios as $usuario) {
            echo "- ID: {$usuario['id']}, Usuario: {$usuario['usuario']}, Tipo: {$usuario['tipo']}, Activo: " . ($usuario['activo'] ? 'Sí' : 'No') . "\n";
        }
    }
} catch (PDOException $e) {
    echo "\n❌ Error al consultar usuarios: " . $e->getMessage() . "\n";
}

// Verificar si hay algún usuario administrador
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM usuarios WHERE tipo = 'admin' AND activo = 1");
    $adminCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "\n=== Verificación de administradores ===\n";
    
    if ($adminCount > 0) {
        echo "✅ Hay $adminCount usuario(s) administrador(es) activo(s)\n";
    } else {
        echo "⚠️ No hay usuarios administradores activos en la base de datos\n";
        
        // Ofrecer crear un usuario administrador si no existe ninguno
        echo "\n¿Desea crear un usuario administrador? (s/n): ";
        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle));
        
        if (strtolower($input) === 's') {
            echo "\nCreando usuario administrador...\n";
            $usuario = 'admin';
            $clave = 'admin123';
            $nombre = 'Administrador';
            $email = 'admin@hermesexpress.com';
            $tipo = 'admin';
            $clave_hash = password_hash($clave, PASSWORD_DEFAULT);
            
            try {
                $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, clave, nombre, email, tipo, activo) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->execute([$usuario, $clave_hash, $nombre, $email, $tipo]);
                
                echo "✅ Usuario administrador creado exitosamente\n";
                echo "Usuario: $usuario\n";
                echo "Contraseña: $clave\n";
                echo "\n⚠️ Por seguridad, cambie esta contraseña después de iniciar sesión.\n";
            } catch (PDOException $e) {
                echo "❌ Error al crear el usuario administrador: " . $e->getMessage() . "\n";
            }
        }
    }
} catch (PDOException $e) {
    echo "\n❌ Error al verificar administradores: " . $e->getMessage() . "\n";
}

echo "\n=== Verificación completada ===\n";
if ($allTablesExist) {
    echo "✅ Todas las tablas requeridas existen\n";
} else {
    echo "⚠️ Algunas tablas o columnas requeridas no existen\n";
    echo "Ejecute el archivo database/hermes_db.sql para crear la estructura completa de la base de datos.\n";
}
?>
