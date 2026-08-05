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
    // Marca la orden como notificada → el timbre deja de sonar para esta orden
    $stmt = $conn->prepare("UPDATE ordenes SET cocina_notificado = 1 WHERE id = ?");
    $stmt->execute([$orden_id]);
    echo json_encode(['success' => true]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
