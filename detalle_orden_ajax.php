<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    echo json_encode(['success'=>false,'error'=>'No autorizado']); exit;
}

$orden_id = (int)($_GET['orden_id'] ?? 0);
if($orden_id <= 0) { echo json_encode(['success'=>false,'error'=>'ID inválido']); exit; }

require_once 'config.php';
try {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT o.id, o.numero_orden, o.nombre_cliente, o.total, o.fecha_creacion,
               GROUP_CONCAT(DISTINCT p.metodo_pago ORDER BY p.metodo_pago SEPARATOR '/') AS metodo_pago
        FROM ordenes o LEFT JOIN pagos p ON p.orden_id = o.id
        WHERE o.id = ?
        GROUP BY o.id
    ");
    $stmt->execute([$orden_id]);
    $orden = $stmt->fetch();
    if(!$orden) { echo json_encode(['success'=>false,'error'=>'No encontrada']); exit; }

    $stmt2 = $conn->prepare("
        SELECT d.cantidad, (d.precio_unitario * d.cantidad) AS subtotal, d.producto_nombre AS nombre
        FROM detalle_orden d
        WHERE d.orden_id=?
    ");
    $stmt2->execute([$orden_id]);
    $items = $stmt2->fetchAll();

    echo json_encode(['success'=>true, 'orden'=>$orden, 'items'=>$items]);
} catch(PDOException $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
?>
