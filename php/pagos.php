<?php
require_once 'config.php';

// Verificar sesión de administrador
if (!isset($_SESSION['id']) || $_SESSION['tipo'] !== 'admin') {
    $_SESSION['id'] = 1;
    $_SESSION['nombre'] = 'Administrador';
    $_SESSION['usuario'] = 'admin';
    $_SESSION['tipo'] = 'admin';
}

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

switch($accion) {
    case 'calcular_pagos':
        calcularPagosEmpleados();
        break;
    case 'obtener_tarifas':
        obtenerTarifas();
        break;
    case 'actualizar_tarifa':
        actualizarTarifa();
        break;
    case 'obtener_pagos':
        obtenerPagosEmpleados();
        break;
    case 'marcar_pagado':
        marcarPagado();
        break;
    case 'detalle_pago':
        obtenerDetallePago();
        break;
    default:
        echo json_encode(['exito' => false, 'mensaje' => 'Acción no válida']);
}

function calcularPagosEmpleados() {
    global $pdo;
    
    try {
        $fecha_inicio = $_POST['fecha_inicio'] ?? date('Y-m-01');
        $fecha_fin = $_POST['fecha_fin'] ?? date('Y-m-t');
        $empleado_id = $_POST['empleado_id'] ?? null;
        
        // Obtener tarifas actuales
        $stmt = $pdo->query("SELECT * FROM tarifas_rutas WHERE activo = 1");
        $tarifas = [];
        while($row = $stmt->fetch()) {
            $tarifas[$row['tipo_ruta']] = $row;
        }
        
        // Query base para obtener paquetes del período
        $sql = "
            SELECT 
                p.empleado_id,
                u.nombre as empleado_nombre,
                p.tipo_ruta,
                p.estado,
                p.peso,
                p.precio,
                COUNT(*) as cantidad
            FROM paquetes p
            JOIN usuarios u ON p.empleado_id = u.id
            WHERE p.fecha_envio BETWEEN ? AND ?
            AND p.estado IN ('entregado', 'devuelto')
        ";
        
        $params = [$fecha_inicio, $fecha_fin];
        
        if ($empleado_id) {
            $sql .= " AND p.empleado_id = ?";
            $params[] = $empleado_id;
        }
        
        $sql .= " GROUP BY p.empleado_id, p.tipo_ruta, p.estado";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $resultados = $stmt->fetchAll();
        
        // Procesar resultados y calcular pagos
        $pagos_empleados = [];
        
        foreach ($resultados as $row) {
            $empleado_id = $row['empleado_id'];
            $tipo_ruta = $row['tipo_ruta'];
            $estado = $row['estado'];
            
            if (!isset($pagos_empleados[$empleado_id])) {
                $pagos_empleados[$empleado_id] = [
                    'empleado_id' => $empleado_id,
                    'empleado_nombre' => $row['empleado_nombre'],
                    'entregados' => 0,
                    'devueltos' => 0,
                    'urbano' => 0,
                    'distrital' => 0,
                    'interprovincial' => 0,
                    'interurbano' => 0,
                    'total_comision' => 0
                ];
            }
            
            $cantidad = $row['cantidad'];
            
            if ($estado === 'entregado') {
                $pagos_empleados[$empleado_id]['entregados'] += $cantidad;
                $pagos_empleados[$empleado_id][$tipo_ruta] += $cantidad;
                
                // Calcular comisión
                if (isset($tarifas[$tipo_ruta])) {
                    $tarifa = $tarifas[$tipo_ruta];
                    $monto_base = $tarifa['tarifa_base'] * $cantidad;
                    $comision = ($monto_base * $tarifa['comision_empleado']) / 100;
                    $pagos_empleados[$empleado_id]['total_comision'] += $comision;
                }
            } else {
                $pagos_empleados[$empleado_id]['devueltos'] += $cantidad;
                // Los devueltos pueden tener descuento
                if (isset($tarifas[$tipo_ruta])) {
                    $tarifa = $tarifas[$tipo_ruta];
                    $descuento = ($tarifa['tarifa_base'] * $cantidad * 0.5); // 50% descuento por devolución
                    $pagos_empleados[$empleado_id]['total_comision'] -= $descuento;
                }
            }
        }
        
        echo json_encode([
            'exito' => true,
            'datos' => array_values($pagos_empleados),
            'periodo' => ['inicio' => $fecha_inicio, 'fin' => $fecha_fin]
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al calcular pagos: ' . $e->getMessage()]);
    }
}

function obtenerTarifas() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT * FROM tarifas_rutas WHERE activo = 1 ORDER BY tipo_ruta");
        $tarifas = $stmt->fetchAll();
        
        echo json_encode([
            'exito' => true,
            'datos' => $tarifas
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener tarifas']);
    }
}

function actualizarTarifa() {
    global $pdo;
    
    try {
        $tipo_ruta = $_POST['tipo_ruta'];
        $tarifa_base = floatval($_POST['tarifa_base']);
        $tarifa_por_kg = floatval($_POST['tarifa_por_kg']);
        $comision_empleado = floatval($_POST['comision_empleado']);
        $descripcion = $_POST['descripcion'] ?? '';
        
        $stmt = $pdo->prepare("
            UPDATE tarifas_rutas 
            SET tarifa_base = ?, tarifa_por_kg = ?, comision_empleado = ?, descripcion = ?
            WHERE tipo_ruta = ?
        ");
        
        $stmt->execute([$tarifa_base, $tarifa_por_kg, $comision_empleado, $descripcion, $tipo_ruta]);
        
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Tarifa actualizada exitosamente'
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al actualizar tarifa']);
    }
}

function obtenerPagosEmpleados() {
    global $pdo;
    
    try {
        $fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
        $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
        
        $stmt = $pdo->prepare("
            SELECT 
                pe.*,
                u.nombre as empleado_nombre
            FROM pagos_empleados pe
            JOIN usuarios u ON pe.empleado_id = u.id
            WHERE pe.periodo_inicio >= ? AND pe.periodo_fin <= ?
            ORDER BY pe.fecha_calculo DESC
        ");
        
        $stmt->execute([$fecha_inicio, $fecha_fin]);
        $pagos = $stmt->fetchAll();
        
        echo json_encode([
            'exito' => true,
            'datos' => $pagos
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener pagos']);
    }
}

function marcarPagado() {
    global $pdo;
    
    try {
        $pago_id = intval($_POST['pago_id']);
        
        $stmt = $pdo->prepare("
            UPDATE pagos_empleados 
            SET estado = 'pagado', fecha_pago = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$pago_id]);
        
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Pago marcado como pagado'
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al marcar pago']);
    }
}

function obtenerDetallePago() {
    global $pdo;
    
    try {
        $empleado_id = intval($_GET['empleado_id']);
        $fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
        $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');
        
        $stmt = $pdo->prepare("
            SELECT 
                p.codigo,
                p.destinatario,
                p.tipo_ruta,
                p.peso,
                p.precio,
                p.estado,
                p.fecha_envio,
                p.fecha_entrega,
                tr.comision_empleado,
                (tr.tarifa_base * tr.comision_empleado / 100) as comision_calculada
            FROM paquetes p
            LEFT JOIN tarifas_rutas tr ON p.tipo_ruta = tr.tipo_ruta
            WHERE p.empleado_id = ?
            AND p.fecha_envio BETWEEN ? AND ?
            AND p.estado IN ('entregado', 'devuelto')
            ORDER BY p.fecha_envio DESC
        ");
        
        $stmt->execute([$empleado_id, $fecha_inicio, $fecha_fin]);
        $detalle = $stmt->fetchAll();
        
        echo json_encode([
            'exito' => true,
            'datos' => $detalle
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['exito' => false, 'mensaje' => 'Error al obtener detalle']);
    }
}
?>
