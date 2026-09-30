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

    require_once 'migrations.php';
    setupCocinaColumns($conn);

    // Cocina la marcó lista y la orden sigue en el salón (aunque ya esté cobrada)
    $activa = "cocina_estado = 2 AND estado IN ('en_cocina','listo','pagado')";

    if($rol === 'admin' || $sucursal === 'ambas') {
        $stmt = $conn->query("SELECT id, numero_orden FROM ordenes WHERE {$activa} ORDER BY id");
    } else {
        $stmt = $conn->prepare("SELECT id, numero_orden FROM ordenes WHERE {$activa} AND sucursal = ? ORDER BY id");
        $stmt->execute([$sucursal]);
    }

    echo json_encode(['success' => true, 'ordenes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error interno']);
}
