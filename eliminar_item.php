<?php
session_start();

$item_id = $_GET['item_id'] ?? 0;
$orden_id = $_SESSION['orden_actual'] ?? 0;

if($item_id > 0 && $orden_id > 0) {
    require_once 'config.php';

    try {
        $conn = getConnection();
        
        // Eliminar item
        $stmt = $conn->prepare("DELETE FROM detalle_orden WHERE id = ? AND orden_id = ?");
        $stmt->execute([$item_id, $orden_id]);
        
        // Actualizar total de la orden
        $stmt = $conn->prepare("
            UPDATE ordenes 
            SET total = (SELECT COALESCE(SUM(precio_unitario * cantidad), 0) FROM detalle_orden WHERE orden_id = ?) 
            WHERE id = ?
        ");
        $stmt->execute([$orden_id, $orden_id]);
        
    } catch(PDOException $e) {
        // Error silencioso
    }
}

header('Location: ver_orden.php');
exit;
?>


