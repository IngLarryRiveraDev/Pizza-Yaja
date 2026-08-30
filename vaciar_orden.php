<?php
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$orden_id = $_SESSION['orden_actual'] ?? 0;

if($orden_id > 0) {
    require_once 'config.php';
    try {
        $conn = getConnection();
        $stmt = $conn->prepare("DELETE FROM detalle_orden WHERE orden_id = ?");
        $stmt->execute([$orden_id]);
        $stmt = $conn->prepare("UPDATE ordenes SET total = 0 WHERE id = ?");
        $stmt->execute([$orden_id]);
    } catch(PDOException $e) {
        // fallo silencioso — la orden sigue visible, no pierde datos
    }
}

header('Location: ver_orden.php');
exit;
