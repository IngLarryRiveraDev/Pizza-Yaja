<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);
$orden_id = $datos['orden_id'] ?? 0;

if($orden_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

require_once 'config.php';

try {
    $conn = getConnection();
    // Cambia estado a 'en_cocina' y resetea la notificación
    // (por si la orden ya fue enviada antes, el timbre vuelve a sonar)
    $stmt = $conn->prepare("UPDATE ordenes SET estado = 'en_cocina', cocina_notificado = 0 WHERE id = ?");
    $stmt->execute([$orden_id]);
    echo json_encode(['success' => true]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
