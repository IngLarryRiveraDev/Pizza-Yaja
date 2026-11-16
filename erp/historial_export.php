<?php
// Datos del historial para exportar a Excel. El archivo .xlsx se arma en el
// navegador (historial.php, con ExcelJS); acá solo se entregan los datos.
// GET: desde=AAAA-MM-DD  hasta=AAAA-MM-DD (exclusivo)
session_start();
header('Content-Type: application/json; charset=UTF-8');
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    echo json_encode(['success' => false, 'error' => 'No autorizado']); exit;
}
require_once '../config.php';
require_once 'includes/periodo.php';

$fechaOk = function($f) { $d = DateTime::createFromFormat('Y-m-d', $f ?? ''); return $d && $d->format('Y-m-d') === $f; };
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
if(!$fechaOk($desde) || !$fechaOk($hasta) || $hasta <= $desde || (strtotime($hasta) - strtotime($desde)) > 370 * 86400) {
    echo json_encode(['success' => false, 'error' => 'Rango de fechas inválido']); exit;
}

// No exportar días que todavía no pasan (ej. el resto del mes actual)
$manana = date('Y-m-d', strtotime('+1 day'));
if($hasta > $manana) $hasta = $manana;
if($hasta <= $desde) { echo json_encode(['success' => false, 'error' => 'Ese período todavía no empieza']); exit; }

$suc_filtro = $_SESSION['erp_sucursal'] ?? 'ambas';
$sucLabels  = ['cariari' => 'Cariari', 'guapiles' => 'Guápiles'];

try {
    $conn = getConnection();
    $where  = "o.estado = 'completado' AND o.fecha_creacion >= ? AND o.fecha_creacion < ?";
    $params = [$desde, $hasta];
    if($suc_filtro !== 'ambas') { $where .= " AND o.sucursal = ?"; $params[] = $suc_filtro; }

    // Órdenes
    $q = $conn->prepare("SELECT o.id, o.numero_orden, o.nombre_cliente, o.sucursal, o.total, o.fecha_creacion
                         FROM ordenes o WHERE {$where} ORDER BY o.fecha_creacion ASC");
    $q->execute($params);
    $ordenes = [];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $ordenes[$o['id']] = [
            'fecha'    => substr($o['fecha_creacion'], 0, 10),
            'hora'     => substr($o['fecha_creacion'], 11, 5),
            'numero'   => (int)$o['numero_orden'],
            'cliente'  => $o['nombre_cliente'] ?: '',
            'sucursal' => $sucLabels[$o['sucursal']] ?? $o['sucursal'],
            'total'    => (float)$o['total'],
            'efectivo' => 0, 'sinpe' => 0, 'tarjeta' => 0,
            'productos'=> [],
        ];
    }

    // Pagos por método
    $q = $conn->prepare("SELECT p.orden_id, p.metodo_pago, SUM(p.monto_aplicado) AS m
                         FROM pagos p JOIN ordenes o ON p.orden_id = o.id WHERE {$where} GROUP BY p.orden_id, p.metodo_pago");
    $q->execute($params);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if(isset($ordenes[$p['orden_id']]) && in_array($p['metodo_pago'], ['efectivo', 'sinpe', 'tarjeta'])) {
            $ordenes[$p['orden_id']][$p['metodo_pago']] += (float)$p['m'];
        }
    }

    // Detalle: lista de productos por orden, vendidos por semana y calzones por día
    $q = $conn->prepare("SELECT d.orden_id, d.producto_nombre, d.cantidad, d.precio_unitario, d.notas
                         FROM detalle_orden d JOIN ordenes o ON d.orden_id = o.id WHERE {$where} ORDER BY d.id");
    $q->execute($params);
    $vendidos = [];   // [lunes][producto] => [qty, total]
    $calzones = [];   // [fecha] => cantidad
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $d) {
        if(!isset($ordenes[$d['orden_id']])) continue;
        $o = &$ordenes[$d['orden_id']];
        $cant = (int)$d['cantidad'];
        $o['productos'][] = $cant . 'x ' . $d['producto_nombre'] . ($d['notas'] ? ' (' . $d['notas'] . ')' : '');
        $lunes = perLunes($o['fecha']);
        $nombre = $d['producto_nombre'];
        $vendidos[$lunes][$nombre][0] = ($vendidos[$lunes][$nombre][0] ?? 0) + $cant;
        $vendidos[$lunes][$nombre][1] = ($vendidos[$lunes][$nombre][1] ?? 0) + $cant * (float)$d['precio_unitario'];
        // Misma regla que el contador del historial: sueltos ('Calzone ...') y en combo ('... Calzone: sabor')
        if(stripos($nombre, 'calzone') !== false) $calzones[$o['fecha']] = ($calzones[$o['fecha']] ?? 0) + $cant;
        unset($o);
    }

    // Eliminadas
    $eWhere  = "fecha >= ? AND fecha < ?";
    $eParams = [$desde, $hasta];
    if($suc_filtro !== 'ambas') { $eWhere .= " AND sucursal = ?"; $eParams[] = $suc_filtro; }
    $eliminadas = [];
    try {
        $q = $conn->prepare("SELECT * FROM log_eliminaciones WHERE {$eWhere} ORDER BY fecha ASC");
        $q->execute($eParams);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $eliminadas[] = [
                'fecha'   => substr($e['fecha'], 0, 10),
                'hora'    => substr($e['fecha'], 11, 5),
                'numero'  => (int)$e['numero_orden'],
                'cliente' => $e['nombre_cliente'] ?: '',
                'sucursal'=> $sucLabels[$e['sucursal'] ?? ''] ?? ($e['sucursal'] ?? ''),
                'total'   => (float)$e['total'],
                'motivo'  => $e['motivo'],
                'por'     => $e['eliminado_por'] ?? '',
            ];
        }
    } catch(PDOException $e) {}

    // Semanas de lunes a domingo, recortadas al rango pedido
    $semanas = [];
    for($l = perLunes($desde); $l < $hasta; $l = date('Y-m-d', strtotime($l . ' +7 days'))) {
        $ini = max($l, $desde);
        $fin = min(date('Y-m-d', strtotime($l . ' +7 days')), $hasta);
        $dias = [];
        for($d = $ini; $d < $fin; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $dias[] = ['fecha' => $d, 'dia' => PER_DIAS_CORTO[date('N', strtotime($d)) - 1], 'calzones' => $calzones[$d] ?? 0];
        }
        $prods = [];
        foreach($vendidos[$l] ?? [] as $n => [$qty, $tot]) $prods[] = ['nombre' => $n, 'cantidad' => $qty, 'total' => $tot];
        usort($prods, fn($a, $b) => $b['cantidad'] <=> $a['cantidad'] ?: strcmp($a['nombre'], $b['nombre']));
        $ultimo = date('Y-m-d', strtotime($fin . ' -1 day'));
        $diaTxt = fn($f) => PER_DIAS_CORTO[date('N', strtotime($f)) - 1] . ' ' . perFechaCorta($f);
        $completa = $ini === $l && $fin === date('Y-m-d', strtotime($l . ' +7 days'));
        $titulo = $ini === $ultimo ? $diaTxt($ini) : 'Semana del ' . $diaTxt($ini) . ' al ' . $diaTxt($ultimo);
        if(!$completa) $titulo .= ' (parte de la semana)';
        $semanas[] = [
            'lunes'     => $l,
            'titulo'    => $titulo,
            'dias'      => $dias,
            'productos' => $prods,
        ];
    }

    echo json_encode([
        'success'    => true,
        'desde'      => $desde,
        'hasta'      => date('Y-m-d', strtotime($hasta . ' -1 day')),
        'sucursal'   => $suc_filtro === 'ambas' ? 'Ambas sucursales' : $sucLabels[$suc_filtro],
        'variasSuc'  => $suc_filtro === 'ambas',
        'generado'   => date('d/m/Y H:i'),
        'semanas'    => $semanas,
        'ordenes'    => array_values(array_map(function($o) { $o['productos'] = implode('; ', $o['productos']); return $o; }, $ordenes)),
        'eliminadas' => $eliminadas,
    ], JSON_UNESCAPED_UNICODE);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error al leer los datos']);
}
