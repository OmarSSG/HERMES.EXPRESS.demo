<?php
function verificarSesion() {
    // Solo iniciar la sesión si no está ya activa
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Verificar si hay una sesión activa
    if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['tipo'])) {
        return false;
    }
    
    // Verificar si la sesión ha expirado (30 minutos de inactividad)
    $tiempo_inactividad = 1800; // 30 minutos en segundos
    if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso'] > $tiempo_inactividad)) {
        // Destruir la sesión si ha expirado
        session_unset();
        session_destroy();
        return false;
    }
    
    // Actualizar el tiempo de último acceso
    $_SESSION['ultimo_acceso'] = time();
    
    return true;
}

function iniciarSesion($usuario_id, $usuario, $nombre, $tipo) {
    session_start();
    
    // Regenerar el ID de sesión para prevenir fijación de sesión
    session_regenerate_id(true);
    
    // Establecer variables de sesión
    $_SESSION['usuario_id'] = $usuario_id;
    $_SESSION['usuario'] = $usuario;
    $_SESSION['nombre'] = $nombre;
    $_SESSION['tipo'] = $tipo;
    $_SESSION['ultimo_acceso'] = time();
    
    // Configurar la cookie de sesión
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => 0, // La cookie expira cuando se cierra el navegador
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function cerrarSesion() {
    // Iniciar la sesión si no está iniciada
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Destruir todas las variables de sesión
    $_SESSION = [];
    
    // Borrar la cookie de sesión
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }
    
    // Destruir la sesión
    session_destroy();
}

function obtenerUsuarioActual() {
    if (!verificarSesion()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['usuario_id'] ?? null,
        'usuario' => $_SESSION['usuario'] ?? null,
        'nombre' => $_SESSION['nombre'] ?? null,
        'tipo' => $_SESSION['tipo'] ?? null
    ];
}
?>
