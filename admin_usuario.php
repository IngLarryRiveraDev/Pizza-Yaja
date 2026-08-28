<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);
$action = $datos['action'] ?? '';

require_once 'config.php';

try {
    $conn = getConnection();

    if($action === 'crear') {
        $nombre   = trim($datos['nombre']   ?? '');
        $usuario  = trim($datos['usuario']  ?? '');
        $password = $datos['password'] ?? '';
        $rol      = $datos['rol']      ?? 'camarero';
        $sucursal = $datos['sucursal'] ?? 'cariari';

        if(!$nombre || !$usuario || !$password) {
            echo json_encode(['success' => false, 'error' => 'Campos incompletos']);
            exit;
        }

        if(!in_array($rol, ['admin', 'camarero', 'cocina'])) {
            echo json_encode(['success' => false, 'error' => 'Rol invalido']);
            exit;
        }

        if(!in_array($sucursal, ['cariari', 'guapiles'])) {
            $sucursal = 'cariari';
        }

        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmt->execute([$usuario]);
        if($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'El usuario ya existe']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO usuarios (nombre, usuario, contrasena, rol, sucursal) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$nombre, $usuario, password_hash($password, PASSWORD_DEFAULT), $rol, $sucursal]);
        echo json_encode(['success' => true]);

    } elseif($action === 'eliminar') {
        $id = (int)($datos['id'] ?? 0);

        // No permitir eliminar al admin actual
        if($id === (int)$_SESSION['usuario_id']) {
            echo json_encode(['success' => false, 'error' => 'No podés eliminar tu propio usuario']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);

    } else {
        echo json_encode(['success' => false, 'error' => 'Acción desconocida']);
    }

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
