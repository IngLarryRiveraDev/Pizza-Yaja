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
    require_once 'migrations.php';
    require_once 'solicitudes_fn.php';
    setupCocinaColumns($conn);
    bloquearSiPendiente($conn, $orden_id);

    // Entra a cocina y se resetea la notificación, para que el timbre vuelva a
    // sonar si la orden ya había sido enviada antes
    $stmt = $conn->prepare("
        UPDATE ordenes
        SET cocina_estado = 1, cocina_notificado = 0,
            estado = CASE WHEN estado = 'pendiente' THEN 'en_cocina' ELSE estado END
        WHERE id = ?
    ");
    $stmt->execute([$orden_id]);
    echo json_encode(['success' => true]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
