<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);
$password = $d['password'] ?? '';

if(empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Ingresá la contraseña']);
    exit;
}

require_once 'config.php';

try {
    $conn = getConnection();
    $stmt = $conn->query("SELECT contrasena FROM usuarios WHERE rol = 'admin' LIMIT 1");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if($admin && password_verify($password, $admin['contrasena'])) {
        $token = hash_hmac('sha256', date('Y-m-d'), 'py_arqueo_2026');
        echo json_encode(['success' => true, 'token' => $token]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error interno']);
}
?>
