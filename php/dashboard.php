<?php
require_once 'config.php';
require_once 'verificar_sesion.php';
verificar_sesion();

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

switch($accion) {
    case 'resumen':
        obtenerResumen();
        break;
    case 'paquetes':
        obtenerPaquetes();
        break;
    case 'rutas':
        obtenerRutas();
        break;
    case 'vehiculos':
        obtenerVehiculos();
        break;
    case 'actividad':
        obtenerActividad();
        break;
    case 'nuevo_paquete':
        crearPaquete();
        break;
    default:
        echo json_encode(['exito' => false, 'mensaje' => 'Acción no válida']);
}

function obtenerResumen() {
    global $pdo;
    
    try {
        // Total de paquetes
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM paquetes");
        $total_paquetes = $stmt->fetch()['total'];
        
        // Paquetes en tránsito
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM paquetes WHERE estado = 'en_transito'");
        $en_transito = $stmt->fetch()['total'];
        
        // Paquetes entregados
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM paquetes WHERE estado = 'entregado'");
        $entregados = $stmt->fetch()['total'];
        
        // Ingresos del día
        $stmt = $pdo->query("SELECT COALESCE(SUM(precio), 0) as total FROM paquetes WHERE DATE(fecha_envio) = CURDATE()");
        $ingresos = $stmt->fetch()['total'];
        
        // Estados para gráfico
        $stmt = $pdo->query("
            SELECT estado, COUNT(*) as cantidad 
            FROM paquetes 
            GROUP BY estado
        ");
        $estados = [];
        while($row = $stmt->fetch()) {
            $estados[$row['estado']] = $row['cantidad'];
        }
        
        echo json_encode([
            'exito' => true,
            'datos' => [
                'total_paquetes' => $total_paquetes,
                'en_transito' => $en_transito,
                'entregados' => $entregados,
                'ingresos' => $ingresos,
                'estados' => $estados
            ]
        ]);
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener resumen']);
    }
}

function obtenerPaquetes() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT p.*, u.nombre as empleado_nombre 
            FROM paquetes p 
            LEFT JOIN usuarios u ON p.empleado_id = u.id 
            ORDER BY p.fecha_creacion DESC
        ");
        $paquetes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['exito' => true, 'datos' => $paquetes]);
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener paquetes']);
    }
}

function obtenerRutas() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT * FROM rutas WHERE activa = 1 ORDER BY nombre");
        $rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['exito' => true, 'datos' => $rutas]);
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener rutas']);
    }
}

function obtenerVehiculos() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT v.*, u.nombre as empleado_nombre 
            FROM vehiculos v 
            LEFT JOIN usuarios u ON v.empleado_id = u.id 
            ORDER BY v.placa
        ");
        $vehiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['exito' => true, 'datos' => $vehiculos]);
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener vehículos']);
    }
}

function obtenerActividad() {
    global $pdo;
    
    try {
        $actividades = [
            [
                'descripcion' => 'Nuevo paquete HE001 registrado',
                'fecha' => date('Y-m-d H:i:s'),
                'tipo' => 'nuevo'
            ],
            [
                'descripcion' => 'Paquete HE003 entregado',
                'fecha' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                'tipo' => 'entregado'
            ],
            [
                'descripcion' => 'Vehículo ABC-123 en ruta',
                'fecha' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'tipo' => 'transito'
            ]
        ];
        
        echo json_encode(['exito' => true, 'datos' => $actividades]);
    } catch(Exception $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener actividad']);
    }
}

function crearPaquete() {
    global $pdo;
    
    try {
        $remitente = limpiar_dato($_POST['remitente']);
        $destinatario = limpiar_dato($_POST['destinatario']);
        $direccion_origen = limpiar_dato($_POST['direccion_origen']);
        $direccion_destino = limpiar_dato($_POST['direccion_destino']);
        $peso = floatval($_POST['peso']);
        $precio = floatval($_POST['precio']);
        
        // Generar código único
        $codigo = 'HE' . str_pad(rand(1, 9999), 3, '0', STR_PAD_LEFT);
        
        $stmt = $pdo->prepare("
            INSERT INTO paquetes (codigo, remitente, destinatario, direccion_origen, direccion_destino, peso, precio, fecha_envio)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())
        ");
        
        $stmt->execute([$codigo, $remitente, $destinatario, $direccion_origen, $direccion_destino, $peso, $precio]);
        
        echo json_encode(['exito' => true, 'mensaje' => 'Paquete creado exitosamente', 'codigo' => $codigo]);
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al crear paquete']);
    }
}
?>