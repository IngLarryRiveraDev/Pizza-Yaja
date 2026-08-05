<?php
session_start();

$orden_id = $_SESSION['orden_actual'] ?? 0;

if($orden_id > 0) {
    require_once 'config.php';

    try {
        $conn = getConnection();
        
        // Eliminar todos los items de la orden
        $stmt = $conn->prepare("DELETE FROM detalle_orden WHERE orden_id = ?");
        $stmt->execute([$orden_id]);
        
        // Actualizar total a 0
        $stmt = $conn->prepare("UPDATE ordenes SET total = 0 WHERE id = ?");
        $stmt->execute([$orden_id]);
        
    } catch(PDOException $e) {
        // Error silencioso
    }
}

header('Location: ver_orden.php');
exit;
?>


