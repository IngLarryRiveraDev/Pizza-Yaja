<?php
session_start();

if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

try {
    $conn = getConnection();

    $fecha = $_GET['fecha'] ?? date('Y-m-d');

    // Totales por método de pago
    $stmt = $conn->prepare("
        SELECT p.metodo_pago, COALESCE(SUM(p.monto_aplicado), 0) as total
        FROM pagos p
        JOIN ordenes o ON p.orden_id = o.id
        WHERE o.estado = 'completado' AND DATE(o.fecha_creacion) = ?
        GROUP BY p.metodo_pago
    ");
    $stmt->execute([$fecha]);
    $pagos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagos = ['efectivo' => 0, 'sinpe' => 0, 'tarjeta' => 0];
    foreach($pagos_raw as $p) $pagos[$p['metodo_pago']] = (float)$p['total'];
    $total_dia = array_sum($pagos);

    // Órdenes del día
    $stmt = $conn->prepare("
        SELECT o.numero_orden, o.nombre_cliente, o.total, o.fecha_creacion,
               (SELECT GROUP_CONCAT(CONCAT(cantidad,'x ',producto_nombre) SEPARATOR ' | ')
                FROM detalle_orden WHERE orden_id = o.id) as productos
        FROM ordenes o
        WHERE o.estado = 'completado' AND DATE(o.fecha_creacion) = ?
        ORDER BY o.fecha_creacion ASC
    ");
    $stmt->execute([$fecha]);
    $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}

$fecha_formato = date('d-m-Y', strtotime($fecha));
$filename = "Arqueo_Caja_" . $fecha_formato . ".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

// BOM para que Excel abra con tildes correctamente
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Encabezado del reporte
fputcsv($out, ['ARQUEO DE CAJA - PIZZA YAJA'], ';');
fputcsv($out, ['Fecha:', $fecha_formato], ';');
fputcsv($out, [], ';');

// Resumen de totales
fputcsv($out, ['RESUMEN DE VENTAS'], ';');
fputcsv($out, ['Método de pago', 'Total'], ';');
fputcsv($out, ['Efectivo', '₡' . number_format($pagos['efectivo'], 0, ',', '.')], ';');
fputcsv($out, ['SINPE', '₡' . number_format($pagos['sinpe'], 0, ',', '.')], ';');
fputcsv($out, ['Tarjeta', '₡' . number_format($pagos['tarjeta'], 0, ',', '.')], ';');
fputcsv($out, ['TOTAL DEL DÍA', '₡' . number_format($total_dia, 0, ',', '.')], ';');
fputcsv($out, ['Órdenes completadas', count($ordenes)], ';');
fputcsv($out, [], ';');

// Detalle de órdenes
fputcsv($out, ['DETALLE DE ÓRDENES'], ';');
fputcsv($out, ['# Orden', 'Cliente / Mesa', 'Hora', 'Productos', 'Total'], ';');

foreach($ordenes as $o) {
    fputcsv($out, [
        '#' . $o['numero_orden'],
        $o['nombre_cliente'] ?? 'Sin asignar',
        date('H:i', strtotime($o['fecha_creacion'])),
        $o['productos'] ?? '',
        '₡' . number_format($o['total'], 0, ',', '.'),
    ], ';');
}

fclose($out);
exit;
?>
