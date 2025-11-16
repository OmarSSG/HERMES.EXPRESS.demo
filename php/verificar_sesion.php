<?php
session_start();
header('Content-Type: application/json');

// Verificar si hay sesión activa
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode([
        'sesion_activa' => false,
        'mensaje' => 'Sesión no válida',
        'redirect' => 'login.html'
    ]);
    exit;
}

echo json_encode([
    'sesion_activa' => true,
    'usuario' => [
        'id' => $_SESSION['usuario_id'],
        'nombre' => $_SESSION['nombre'],
        'usuario' => $_SESSION['usuario'],
        'tipo' => $_SESSION['tipo']
    ]
]);
?>
