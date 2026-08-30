<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);
$orden_id    = $datos['orden_id']    ?? 0;
$nombre_mesa = $datos['nombre_mesa'] ?? '';

if($orden_id == 0 || empty($nombre_mesa)) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

require_once 'config.php';

try {
    $conn = getConnection();
    $stmt = $conn->prepare("UPDATE ordenes SET nombre_cliente = ? WHERE id = ?");
    $stmt->execute([$nombre_mesa, $orden_id]);
    echo json_encode(['success' => true]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error al actualizar']);
}
