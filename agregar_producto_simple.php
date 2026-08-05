<?php
session_start();

header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Recibir datos POST
$datos = json_decode(file_get_contents('php://input'), true);

// ConexiÃ³n BD
require_once 'config.php';

try {
    $conn = getConnection();
    
    if(!isset($_SESSION['orden_actual'])) {
        // Crear nueva orden automáticamente
        $conn->beginTransaction();
        
        // Obtener siguiente número de orden
        $stmt = $conn->query("SELECT COALESCE(MAX(numero_orden), 0) + 1 as siguiente FROM ordenes WHERE DATE(fecha_creacion) = CURDATE()");
        $numero_orden = $stmt->fetch(PDO::FETCH_ASSOC)['siguiente'];
        
        // Crear orden
        $stmt = $conn->prepare("
            INSERT INTO ordenes (numero_orden, nombre_cliente, tipo_servicio, total, estado) 
            VALUES (?, 'Orden Automática', 'normal', 0, 'pendiente')
        ");
        $stmt->execute([$numero_orden]);
        
        $_SESSION['orden_actual'] = $conn->lastInsertId();
        
        $conn->commit();
    }

    $orden_id = $_SESSION['orden_actual'];
    
    // Verificar si el producto ya existe en la orden (comparando producto_nombre y notas)
    $stmt = $conn->prepare("
        SELECT id, cantidad FROM detalle_orden
        WHERE orden_id = ? AND producto_nombre = ? AND notas = ?
    ");
    $stmt->execute([
        $orden_id,
        $datos['producto_nombre'],
        $datos['comentarios'] ?? ''
    ]);
    $existe = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($existe) {
        // Si existe, incrementar cantidad
        $nueva_cantidad = $existe['cantidad'] + $datos['cantidad'];
        $stmt = $conn->prepare("UPDATE detalle_orden SET cantidad = ? WHERE id = ?");
        $stmt->execute([$nueva_cantidad, $existe['id']]);
    } else {
        // Si no existe, insertar nuevo
        $stmt = $conn->prepare("
            INSERT INTO detalle_orden (orden_id, producto_nombre, cantidad, precio_unitario, notas) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $orden_id,
            $datos['producto_nombre'],
            $datos['cantidad'],
            $datos['precio'],
            $datos['comentarios'] ?? ''
        ]);
    }
    
    // Actualizar total de la orden
    $stmt = $conn->prepare("
        UPDATE ordenes 
        SET total = (SELECT SUM(precio_unitario * cantidad) FROM detalle_orden WHERE orden_id = ?) 
        WHERE id = ?
    ");
    $stmt->execute([$orden_id, $orden_id]);
    
    // Contar items en la orden
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM detalle_orden WHERE orden_id = ?");
    $stmt->execute([$orden_id]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total_items' => $count['total']
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>