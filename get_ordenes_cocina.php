<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

require_once 'config.php';
require_once 'migrations.php';

try {
    $conn = getConnection();
    setupSucursalColumns($conn);

    $sucursal = $_SESSION['sucursal'] ?? 'cariari';
    $rol      = $_SESSION['rol']      ?? 'cocina';

    if($rol === 'admin') {
        $stmt = $conn->query("
            SELECT id, numero_orden, nombre_cliente, tipo_servicio, total, fecha_creacion, cocina_notificado
            FROM ordenes
            WHERE estado = 'en_cocina'
            ORDER BY fecha_creacion ASC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT id, numero_orden, nombre_cliente, tipo_servicio, total, fecha_creacion, cocina_notificado
            FROM ordenes
            WHERE estado = 'en_cocina'
              AND sucursal = ?
            ORDER BY fecha_creacion ASC
        ");
        $stmt->execute([$sucursal]);
    }
    $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agregar los productos de cada orden
    foreach($ordenes as &$orden) {
        $stmt2 = $conn->prepare("SELECT producto_nombre, cantidad, notas FROM detalle_orden WHERE orden_id = ?");
        $stmt2->execute([$orden['id']]);
        $orden['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'ordenes' => $ordenes]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
