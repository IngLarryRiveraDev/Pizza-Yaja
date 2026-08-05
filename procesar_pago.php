<?php
session_start();

header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Recibir datos
$datos = json_decode(file_get_contents('php://input'), true);
$orden_id = $datos['orden_id'] ?? 0;
$pagos = $datos['pagos'] ?? [];
$vuelto = $datos['vuelto'] ?? 0;

if($orden_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID de orden inválido']);
    exit;
}

// ConexiÃ³n BD
require_once 'config.php';
require_once 'descontar_ingredientes_fn.php';

try {
    $conn = getConnection();
    
    // Iniciar transacciÃ³n
    $conn->beginTransaction();
    
    // Obtener informaciÃ³n de la orden
    $stmt = $conn->prepare("SELECT total FROM ordenes WHERE id = ?");
    $stmt->execute([$orden_id]);
    $orden = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$orden) {
        throw new Exception('Orden no encontrada');
    }
    
    // Verificar cuÃ¡nto ya se ha pagado
    $stmt = $conn->prepare("SELECT SUM(monto_aplicado) as pagado FROM pagos WHERE orden_id = ?");
    $stmt->execute([$orden_id]);
    $pago_previo = $stmt->fetch(PDO::FETCH_ASSOC);
    $ya_pagado = $pago_previo['pagado'] ?? 0;
    
    // Calcular total de este pago
    $total_este_pago = 0;
    foreach($pagos as $metodo => $monto) {
        if($monto > 0) {
            $total_este_pago += $monto;
        }
    }
    
    // Validar que no se pague mÃ¡s de lo debido (considerando vuelto)
    $total_orden = $orden['total'];
    $pendiente = $total_orden - $ya_pagado;
    
    if($total_este_pago - $vuelto < $pendiente) {
        throw new Exception('El monto pagado es insuficiente');
    }
    
    // Insertar pagos
    $stmt = $conn->prepare("
        INSERT INTO pagos (orden_id, metodo_pago, monto_recibido, monto_aplicado, vuelto) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    // Efectivo
    if($pagos['efectivo'] > 0) {
        $monto_aplicado_efectivo = min($pagos['efectivo'], $pendiente);
        $stmt->execute([
            $orden_id,
            'efectivo',
            $pagos['efectivo'],
            $monto_aplicado_efectivo,
            $vuelto
        ]);
        $pendiente -= $monto_aplicado_efectivo;
    }
    
    // SINPE
    if($pagos['sinpe'] > 0 && $pendiente > 0) {
        $monto_aplicado_sinpe = min($pagos['sinpe'], $pendiente);
        $stmt->execute([
            $orden_id,
            'sinpe',
            $pagos['sinpe'],
            $monto_aplicado_sinpe,
            0
        ]);
        $pendiente -= $monto_aplicado_sinpe;
    }
    
    // Tarjeta
    if($pagos['tarjeta'] > 0 && $pendiente > 0) {
        $monto_aplicado_tarjeta = min($pagos['tarjeta'], $pendiente);
        $stmt->execute([
            $orden_id,
            'tarjeta',
            $pagos['tarjeta'],
            $monto_aplicado_tarjeta,
            0
        ]);
        $pendiente -= $monto_aplicado_tarjeta;
    }
    
    // Actualizar estado de la orden si está completamente pagada
    if($pendiente <= 0) {
        $stmt = $conn->prepare("UPDATE ordenes SET estado = 'pagado' WHERE id = ?");
        $stmt->execute([$orden_id]);
    }
    
    // Confirmar transacción
    $conn->commit();

    // Descontar ingredientes del inventario (fuera de la transacción de pago)
    if($pendiente <= 0) {
        try { descontarIngredientes($conn, $orden_id); } catch(Exception $e) {}
    }

    echo json_encode([
        'success' => true,
        'vuelto' => $vuelto
    ]);
    
} catch(Exception $e) {
    if(isset($conn)) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>


