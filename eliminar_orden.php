<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success'=>false,'error'=>'No autenticado']); exit;
}

$d        = json_decode(file_get_contents('php://input'), true);
$orden_id = (int)($d['orden_id'] ?? 0);
$motivo   = trim($d['motivo'] ?? '');

if($orden_id <= 0) {
    echo json_encode(['success'=>false,'error'=>'ID inválido']); exit;
}

require_once 'config.php';
require_once 'solicitudes_fn.php';
try {
    $conn = getConnection();
    setupSolicitudes($conn);

    $stmt = $conn->prepare("SELECT * FROM ordenes WHERE id=?");
    $stmt->execute([$orden_id]);
    $orden = $stmt->fetch();
    if(!$orden) { echo json_encode(['success'=>false,'error'=>'Orden no encontrada']); exit; }

    $cntQ = $conn->prepare("SELECT COUNT(*) FROM detalle_orden WHERE orden_id=?");
    $cntQ->execute([$orden_id]);
    $tieneProductos = (int)$cntQ->fetchColumn() > 0;

    $es_admin = ($_SESSION['rol'] ?? '') === 'admin';

    // Una orden vacía no tiene nada que revisar: se borra de una
    if(!$tieneProductos || $es_admin) {
        if($tieneProductos && $motivo === '') {
            echo json_encode(['success'=>false,'error'=>'motivo_requerido']); exit;
        }
        if($tieneProductos) {
            eliminarOrdenAprobada($conn, $orden_id, $motivo, $_SESSION['nombre']);
        } else {
            $conn->prepare("DELETE FROM pagos         WHERE orden_id=?")->execute([$orden_id]);
            $conn->prepare("DELETE FROM detalle_orden WHERE orden_id=?")->execute([$orden_id]);
            $conn->prepare("DELETE FROM ordenes       WHERE id=?")->execute([$orden_id]);
        }
        if(isset($_SESSION['orden_actual']) && $_SESSION['orden_actual'] == $orden_id) {
            unset($_SESSION['orden_actual']);
        }
        echo json_encode(['success'=>true, 'eliminada'=>true]);
        exit;
    }

    // Con productos: la borra la administración, no el camarero
    if($motivo === '') {
        echo json_encode(['success'=>false,'error'=>'motivo_requerido']); exit;
    }
    if(solicitudEliminacionPendiente($conn, $orden_id)) {
        echo json_encode(['success'=>false,'error'=>'Ya hay una solicitud esperando respuesta para esta orden']); exit;
    }

    $conn->prepare("
        INSERT INTO solicitudes (tipo, orden_id, numero_orden, usuario_id, sucursal, motivo)
        VALUES ('eliminacion', ?, ?, ?, ?, ?)
    ")->execute([
        $orden_id,
        $orden['numero_orden'],
        $_SESSION['usuario_id'],
        $orden['sucursal'] ?? ($_SESSION['sucursal'] ?? 'cariari'),
        $motivo
    ]);

    echo json_encode(['success'=>true, 'eliminada'=>false, 'pendiente'=>true]);

} catch(PDOException $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
?>
