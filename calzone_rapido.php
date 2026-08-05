<?php
session_start();

header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Recibir datos
$rawInput = file_get_contents('php://input');
$datos = json_decode($rawInput, true);
if (!is_array($datos) || !isset($datos['cantidad'])) {
    echo json_encode(['success' => false, 'error' => 'JSON inválido o cantidad no proporcionada']);
    exit;
}

$cantidad = (int) $datos['cantidad'];
if ($cantidad < 1) {
    echo json_encode(['success' => false, 'error' => 'Cantidad inválida']);
    exit;
}

// ConexiÃ³n BD
require_once 'config.php';

try {
    $conn = getConnection();
    
    // Iniciar transacciÃ³n
    $conn->beginTransaction();
    
    // Obtener siguiente nÃºmero de orden
    $stmt = $conn->query("SELECT COALESCE(MAX(numero_orden), 0) + 1 as siguiente FROM ordenes WHERE DATE(fecha_creacion) = CURDATE()");
    $numero_orden = $stmt->fetch(PDO::FETCH_ASSOC)['siguiente'];
    
    // Calcular total
    $precio_unitario = 1000;
    $total = $precio_unitario * $cantidad;
    
    // Crear orden
    $stmt = $conn->prepare("
        INSERT INTO ordenes (numero_orden, nombre_cliente, tipo_servicio, total, estado, cocina_notificado)
        VALUES (?, 'Calzone Express', 'express', ?, 'pendiente', 0)
    ");
    $stmt->execute([$numero_orden, $total]);

    $orden_id = $conn->lastInsertId();

    // Insertar calzones en el detalle
    $stmt = $conn->prepare("
        INSERT INTO detalle_orden (orden_id, producto_id, producto_nombre, cantidad, precio_unitario, notas)
        VALUES (?, 18, 'Calzone Jamón y Queso', ?, ?, '')
    ");
    $stmt->execute([$orden_id, $cantidad, $precio_unitario]);

    // Confirmar transacción
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'orden_id' => $orden_id,
        'numero_orden' => $numero_orden
    ]);
    
} catch(Exception $e) {
    if(isset($conn)) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>


