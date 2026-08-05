<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$orden_id = $_GET['orden_id'] ?? 0;

if($orden_id <= 0) {
    header('Location: ordenes_activas.php');
    exit;
}

require_once 'config.php';
require_once 'descontar_ingredientes_fn.php';

try {
    $conn = getConnection();

    // Verificar que la orden existe y está pagada
    $stmt = $conn->prepare("SELECT total FROM ordenes WHERE id = ?");
    $stmt->execute([$orden_id]);
    $orden = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$orden) {
        header('Location: ordenes_activas.php');
        exit;
    }

    $stmt = $conn->prepare("SELECT SUM(monto_aplicado) as pagado FROM pagos WHERE orden_id = ?");
    $stmt->execute([$orden_id]);
    $pago_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $esta_pagada = ($pago_info['pagado'] >= $orden['total']);

    if(!$esta_pagada) {
        header('Location: ordenes_activas.php');
        exit;
    }

    // Archivar la orden
    $stmt = $conn->prepare("UPDATE ordenes SET estado = 'completado' WHERE id = ?");
    $stmt->execute([$orden_id]);

    // Descontar ingredientes del inventario según receta
    try { descontarIngredientes($conn, $orden_id); } catch(Exception $e) {}

    // Limpiar sesión si era la orden activa
    if(isset($_SESSION['orden_actual']) && $_SESSION['orden_actual'] == $orden_id) {
        unset($_SESSION['orden_actual']);
    }

} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}

header('Location: ordenes_activas.php');
exit;
?>
