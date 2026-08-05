<?php
session_start();

// ConexiÃ³n a la base de datos
require_once 'config.php';

try {
    $conn = getConnection();
    
    // Obtener datos del formulario
    $usuario = $_POST['usuario'];
    $contrasena = $_POST['contrasena'];
    
    // Buscar usuario en la base de datos
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE usuario = :usuario AND activo = 1");
    $stmt->bindParam(':usuario', $usuario);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar si existe y la contraseÃ±a es correcta
    if($user && password_verify($contrasena, $user['contrasena'])) {
        // Login exitoso
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['rol'] = $user['rol'];
        
        // Redirigir según el rol
        if($user['rol'] == 'admin') {
            header('Location: erp/index.php');
        } elseif($user['rol'] == 'cocina') {
            header('Location: cocina.php');
        } else {
            header('Location: ordenes_activas.php');
        }
        exit;
    } else {
        // Login fallido
        header('Location: index.php?error=1');
        exit;
    }
    
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>


