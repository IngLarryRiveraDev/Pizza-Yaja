<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

if(!isset($_SESSION['usuario_id'])) {
    die("<h2>Error: No estás logueado</h2><p>Debes iniciar sesión primero. <a href='index.php'>Ir a Login</a></p>");
}

// Conexión BD
require_once 'config.php';

try {
    $conn = getConnection();
    
    // Obtener siguiente número de orden
    $stmt = $conn->query("SELECT COALESCE(MAX(numero_orden), 0) + 1 as siguiente FROM ordenes WHERE DATE(fecha_creacion) = CURDATE()");
    $numero_orden = $stmt->fetch(PDO::FETCH_ASSOC)['siguiente'];
    
    // Crear orden vacía
    $stmt = $conn->prepare("
        INSERT INTO ordenes (numero_orden, nombre_cliente, tipo_servicio, total, estado) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$numero_orden, 'Sin asignar', 'normal', 0, 'pendiente']);
    
    $orden_id = $conn->lastInsertId();
    
    // Guardar como orden actual en sesión
    $_SESSION['orden_actual'] = $orden_id;
    
    // Redirigir al menú
    header('Location: menu.php');
    exit;
    
} catch(PDOException $e) {
    die("<h2>Error al crear orden</h2><p>" . htmlspecialchars($e->getMessage()) . "</p><p><a href='ordenes_activas.php'>Volver</a></p>");
}
?>


