<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$d        = json_decode(file_get_contents('php://input'), true);
$orden_id = (int)($d['orden_id'] ?? 0);
$tipo     = $d['tipo'] ?? '';

if($orden_id <= 0 || !in_array($tipo, ['local', 'express'])) {
    echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
    exit;
}

require_once 'config.php';
require_once 'solicitudes_fn.php';

try {
    $conn = getConnection();
    bloquearSiPendiente($conn, $orden_id);

    $conn->prepare("UPDATE ordenes SET tipo_servicio = ? WHERE id = ?")->execute([$tipo, $orden_id]);
    echo json_encode(['success' => true, 'tipo' => $tipo]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error al cambiar el tipo de servicio']);
}
