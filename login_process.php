<?php
session_start();

require_once 'config.php';
require_once 'migrations.php';

try {
    $conn = getConnection();
    setupSucursalColumns($conn);

    $usuario   = $_POST['usuario']    ?? '';
    $contrasena = $_POST['contrasena'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE usuario = :usuario AND activo = 1");
    $stmt->bindParam(':usuario', $usuario);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if($user && password_verify($contrasena, $user['contrasena'])) {
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['nombre']     = $user['nombre'];
        $_SESSION['rol']        = $user['rol'];
        $_SESSION['sucursal']   = $user['sucursal'] ?? 'cariari';

        if($user['rol'] == 'admin') {
            header('Location: erp/index.php');
        } elseif($user['rol'] == 'cocina') {
            header('Location: cocina.php');
        } else {
            header('Location: ordenes_activas.php');
        }
        exit;
    } else {
        header('Location: index.php?error=1');
        exit;
    }

} catch(PDOException $e) {
    die("Error de conexion: " . htmlspecialchars($e->getMessage()));
}
