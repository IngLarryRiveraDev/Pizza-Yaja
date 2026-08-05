<?php
session_start();

header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);

if(!isset($datos['nombre']) || !isset($datos['precio'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

require_once 'config.php';

try {
    $conn = getConnection();

    if(!isset($_SESSION['orden_actual'])) {
        $conn->beginTransaction();

        $stmt = $conn->query("SELECT COALESCE(MAX(numero_orden), 0) + 1 as siguiente FROM ordenes WHERE DATE(fecha_creacion) = CURDATE()");
        $numero_orden = $stmt->fetch(PDO::FETCH_ASSOC)['siguiente'];

        $stmt = $conn->prepare("
            INSERT INTO ordenes (numero_orden, nombre_cliente, tipo_servicio, total, estado)
            VALUES (?, 'Orden Automática', 'normal', 0, 'pendiente')
        ");
        $stmt->execute([$numero_orden]);

        $_SESSION['orden_actual'] = $conn->lastInsertId();

        $conn->commit();
    }

    $orden_id = $_SESSION['orden_actual'];

    // Si ya existe el mismo combo en la orden, incrementar cantidad
    $stmt = $conn->prepare("
        SELECT id, cantidad FROM detalle_orden
        WHERE orden_id = ? AND producto_nombre = ?
    ");
    $stmt->execute([$orden_id, $datos['nombre']]);
    $existe = $stmt->fetch(PDO::FETCH_ASSOC);

    if($existe) {
        $stmt = $conn->prepare("UPDATE detalle_orden SET cantidad = cantidad + 1 WHERE id = ?");
        $stmt->execute([$existe['id']]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO detalle_orden (orden_id, producto_nombre, cantidad, precio_unitario, notas)
            VALUES (?, ?, 1, ?, '')
        ");
        $stmt->execute([$orden_id, $datos['nombre'], $datos['precio']]);
    }

    // Actualizar total de la orden
    $stmt = $conn->prepare("
        UPDATE ordenes
        SET total = (SELECT SUM(precio_unitario * cantidad) FROM detalle_orden WHERE orden_id = ?)
        WHERE id = ?
    ");
    $stmt->execute([$orden_id, $orden_id]);

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
