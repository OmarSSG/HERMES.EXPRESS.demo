<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = isset($_POST['usuario']) ? limpiar_dato($_POST['usuario']) : '';
    $clave = isset($_POST['clave']) ? $_POST['clave'] : '';
    
    // Credenciales predefinidas para prueba
    $credenciales_demo = [
        'admin' => [
            'clave' => 'admin123',
            'nombre' => 'Administrador',
            'tipo' => 'admin'
        ],
        'asistente' => [
            'clave' => 'asistente123',
            'nombre' => 'Asistente',
            'tipo' => 'asistente'
        ],
        'empleado' => [
            'clave' => 'empleado123',
            'nombre' => 'Empleado',
            'tipo' => 'empleado'
        ]
    ];
    
    // Verificar credenciales demo
    if (isset($credenciales_demo[$usuario]) && $credenciales_demo[$usuario]['clave'] === $clave) {
        $_SESSION['usuario_id'] = $usuario;
        $_SESSION['nombre'] = $credenciales_demo[$usuario]['nombre'];
        $_SESSION['usuario'] = $usuario;
        $_SESSION['tipo'] = $credenciales_demo[$usuario]['tipo'];
        
        echo json_encode([
            'success' => true,
            'mensaje' => 'Login exitoso',
            'tipo' => $credenciales_demo[$usuario]['tipo'],
            'usuario' => [
                'nombre' => $credenciales_demo[$usuario]['nombre'],
                'tipo' => $credenciales_demo[$usuario]['tipo']
            ]
        ]);
        exit;
    }
    
    // Intentar login con base de datos si existe
    try {
        $stmt = $pdo->prepare("SELECT id, nombre, usuario, clave, tipo FROM usuarios WHERE usuario = ? AND activo = 1");
        $stmt->execute([$usuario]);
        $usuarioData = $stmt->fetch();
        
        if ($usuarioData && password_verify($clave, $usuarioData['clave'])) {
            $_SESSION['usuario_id'] = $usuarioData['id'];
            $_SESSION['nombre'] = $usuarioData['nombre'];
            $_SESSION['usuario'] = $usuarioData['usuario'];
            $_SESSION['tipo'] = $usuarioData['tipo'];
            
            echo json_encode([
                'success' => true,
                'mensaje' => 'Login exitoso',
                'tipo' => $usuarioData['tipo'],
                'usuario' => [
                    'nombre' => $usuarioData['nombre'],
                    'tipo' => $usuarioData['tipo']
                ]
            ]);
            exit;
        }
    } catch(PDOException $e) {
        // Si no existe la tabla, continuar con credenciales demo
    }
    
    // Credenciales incorrectas
    echo json_encode([
        'success' => false, 
        'mensaje' => 'Usuario o contraseña incorrectos'
    ]);
    
} else {
    echo json_encode([
        'success' => false, 
        'mensaje' => 'Método no permitido'
    ]);
}
?>