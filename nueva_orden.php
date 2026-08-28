<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

if(!isset($_SESSION['usuario_id'])) {
    die("<h2>Error: No estas logueado</h2><p>Debes iniciar sesion primero. <a href='index.php'>Ir a Login</a></p>");
}

require_once 'config.php';
require_once 'migrations.php';

try {
    $conn = getConnection();
    setupSucursalColumns($conn);

    $sucursal = $_SESSION['sucursal'] ?? 'cariari';

    $stmt = $conn->query("SELECT COALESCE(MAX(numero_orden), 0) + 1 as siguiente FROM ordenes WHERE DATE(fecha_creacion) = CURDATE()");
    $numero_orden = $stmt->fetch(PDO::FETCH_ASSOC)['siguiente'];

    $stmt = $conn->prepare("
        INSERT INTO ordenes (numero_orden, nombre_cliente, tipo_servicio, total, estado, sucursal)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$numero_orden, 'Sin asignar', 'local', 0, 'pendiente', $sucursal]);

    $orden_id = $conn->lastInsertId();
    $_SESSION['orden_actual'] = $orden_id;

    header('Location: menu.php');
    exit;

} catch(PDOException $e) {
    die("<h2>Error al crear orden</h2><p>" . htmlspecialchars($e->getMessage()) . "</p><p><a href='ordenes_activas.php'>Volver</a></p>");
}
