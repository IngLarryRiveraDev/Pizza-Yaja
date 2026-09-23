<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

require_once 'config.php';

try {
    $conn = getConnection();

    $sucursal = $_SESSION['sucursal'] ?? 'cariari';
    $rol      = $_SESSION['rol']      ?? 'camarero';

    if($rol === 'admin' || $sucursal === 'ambas') {
        $stmt = $conn->query("SELECT id, numero_orden FROM ordenes WHERE estado = 'listo' ORDER BY id");
    } else {
        $stmt = $conn->prepare("SELECT id, numero_orden FROM ordenes WHERE estado = 'listo' AND sucursal = ? ORDER BY id");
        $stmt->execute([$sucursal]);
    }

    echo json_encode(['success' => true, 'ordenes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error interno']);
}
