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
try {
    $conn = getConnection();

    // Auto-crear tabla de log si no existe
    $conn->exec("
        CREATE TABLE IF NOT EXISTS log_eliminaciones (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            orden_id       INT,
            numero_orden   INT,
            nombre_cliente VARCHAR(200),
            total          DECIMAL(10,2),
            motivo         TEXT NOT NULL,
            eliminado_por  VARCHAR(100),
            fecha          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Obtener datos de la orden antes de eliminar
    $stmt = $conn->prepare("SELECT * FROM ordenes WHERE id=?");
    $stmt->execute([$orden_id]);
    $orden = $stmt->fetch();
    if(!$orden) { echo json_encode(['success'=>false,'error'=>'Orden no encontrada']); exit; }

    // Contar productos
    $cntQ = $conn->prepare("SELECT COUNT(*) FROM detalle_orden WHERE orden_id=?");
    $cntQ->execute([$orden_id]);
    $tieneProductos = (int)$cntQ->fetchColumn() > 0;

    // Si tiene productos, motivo obligatorio
    if($tieneProductos && $motivo === '') {
        echo json_encode(['success'=>false,'error'=>'motivo_requerido']); exit;
    }

    // Guardar en log si tenía productos
    if($tieneProductos) {
        $conn->prepare("
            INSERT INTO log_eliminaciones (orden_id, numero_orden, nombre_cliente, total, motivo, eliminado_por)
            VALUES (?,?,?,?,?,?)
        ")->execute([
            $orden_id,
            $orden['numero_orden'],
            $orden['nombre_cliente'],
            $orden['total'],
            $motivo,
            $_SESSION['nombre']
        ]);
    }

    // Eliminar en cascada
    $conn->prepare("DELETE FROM pagos        WHERE orden_id=?")->execute([$orden_id]);
    $conn->prepare("DELETE FROM detalle_orden WHERE orden_id=?")->execute([$orden_id]);
    $conn->prepare("DELETE FROM ordenes       WHERE id=?")->execute([$orden_id]);

    if(isset($_SESSION['orden_actual']) && $_SESSION['orden_actual'] == $orden_id) {
        unset($_SESSION['orden_actual']);
    }

    echo json_encode(['success'=>true]);

} catch(PDOException $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
?>
