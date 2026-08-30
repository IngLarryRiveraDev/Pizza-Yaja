<?php
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$item_id  = (int)($_GET['item_id'] ?? 0);
$orden_id = (int)($_SESSION['orden_actual'] ?? 0);

if($item_id > 0 && $orden_id > 0) {
    require_once 'config.php';
    try {
        $conn = getConnection();
        $stmt = $conn->prepare("DELETE FROM detalle_orden WHERE id = ? AND orden_id = ?");
        $stmt->execute([$item_id, $orden_id]);
        $stmt = $conn->prepare("
            UPDATE ordenes
            SET total = (SELECT COALESCE(SUM(precio_unitario * cantidad), 0) FROM detalle_orden WHERE orden_id = ?)
            WHERE id = ?
        ");
        $stmt->execute([$orden_id, $orden_id]);
    } catch(PDOException $e) {
        // fallo silencioso
    }
}

header('Location: ver_orden.php');
exit;
