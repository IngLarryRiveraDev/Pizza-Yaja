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
    setupCocinaColumns($conn);

    // Cocina terminó: sale de la pantalla de cocina y el camarero la ve LISTA.
    // El estado de pago no se toca, por si ya estaba cobrada.
    $stmt = $conn->prepare("
        UPDATE ordenes
        SET cocina_estado = 2,
            estado = CASE WHEN estado = 'en_cocina' THEN 'listo' ELSE estado END
        WHERE id = ?
    ");
    $stmt->execute([$orden_id]);
    echo json_encode(['success' => true]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
