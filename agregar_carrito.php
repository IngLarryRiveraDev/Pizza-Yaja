<?php
session_start();

header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

if(!isset($_SESSION['orden_actual'])) {
    echo json_encode(['success' => false, 'error' => 'No hay orden activa']);
    exit;
}

$orden_id = $_SESSION['orden_actual'];

// Recibir datos POST
$datos = json_decode(file_get_contents('php://input'), true);

// ConexiÃ³n BD
require_once 'config.php';

try {
    $conn = getConnection();
    
    // Construir descripciÃ³n del producto
    $producto_nombre = '';
    $notas = $datos['comentarios'] ?? '';
    
    if($datos['tipo'] == 'pizza_2x1') {
        $producto_nombre = "Pizza 2x1 - " . $datos['tamano'];
        $detalles = $datos['detalles'];
        
        $descripcion_sabores = "Pizza 1: " . ($detalles['cambio_pizza1'] && isset($detalles['pizza1']) ? $detalles['pizza1']['nombre'] : 'Jamón y Queso');
        $descripcion_sabores .= " | Pizza 2: " . ($detalles['pizza2']['nombre'] ?? 'Sin especificar');
        
        $notas = $descripcion_sabores . ($notas ? " - " . $notas : "");
        
    } elseif($datos['tipo'] == 'pizza_individual') {
        $producto_nombre = "Pizza Individual - " . $datos['tamano'];
        $detalles = $datos['detalles'];
        
        if($detalles['es_mitad_mitad']) {
            $descripcion_sabores = "Mitad: " . ($detalles['mitad1']['nombre'] ?? '?') . " | Mitad: " . ($detalles['mitad2']['nombre'] ?? '?');
        } else {
            $descripcion_sabores = "Sabor: " . ($detalles['pizza1']['nombre'] ?? 'Sin especificar');
        }
        
        $notas = $descripcion_sabores . ($notas ? " - " . $notas : "");
    }
    
    // Verificar si el producto ya existe en la orden (comparando producto_nombre y notas)
    $stmt = $conn->prepare("
        SELECT id, cantidad FROM detalle_orden
        WHERE orden_id = ? AND producto_nombre = ? AND notas = ?
    ");
    $stmt->execute([
        $orden_id,
        $producto_nombre,
        $notas
    ]);
    $existe = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($existe) {
        // Si existe, incrementar cantidad
        $nueva_cantidad = $existe['cantidad'] + ($datos['cantidad'] ?? 1);
        $stmt = $conn->prepare("UPDATE detalle_orden SET cantidad = ? WHERE id = ?");
        $stmt->execute([$nueva_cantidad, $existe['id']]);
    } else {
        // Si no existe, insertar nuevo
        // Insertar en detalle_orden
        $stmt = $conn->prepare("
            INSERT INTO detalle_orden (orden_id, producto_nombre, cantidad, precio_unitario, notas) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $orden_id,
            $producto_nombre,
            $datos['cantidad'] ?? 1,
            $datos['precio'],
            $notas
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