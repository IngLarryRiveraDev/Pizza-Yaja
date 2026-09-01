<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

$es_admin    = isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
$token       = $_GET['t'] ?? '';
$token_ok    = hash_equals(hash_hmac('sha256', date('Y-m-d'), 'py_arqueo_2026'), $token);
if(!isset($_SESSION['usuario_id']) || (!$es_admin && !$token_ok)) {
    header('Location: ordenes_activas.php');
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
               (SELECT GROUP_CONCAT(producto_nombre SEPARATOR ', ') FROM detalle_orden WHERE orden_id = o.id) as productos
        FROM ordenes o
        WHERE o.estado = 'completado' AND DATE(o.fecha_creacion) = ?
        ORDER BY o.fecha_creacion ASC
    ");
    $stmt->execute([$fecha]);
    $ordenes_dia = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arqueo de Caja - Pizza Yaja</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #1a1a1a; padding: 12px; }

        .header {
            background: white; padding: 12px 15px; border-radius: 10px;
            margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;
        }
        .header h1 { color: #ff9800; font-size: 18px; }
        .back-btn {
            background: #666; color: white; padding: 8px 14px;
            border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold;
        }

        .filtro-fecha {
            background: white; border-radius: 8px; padding: 10px 12px;
            margin-bottom: 12px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .filtro-fecha label { font-size: 13px; font-weight: bold; }
        .filtro-fecha input {
            padding: 7px 10px; border: 2px solid #ddd; border-radius: 5px; font-size: 14px;
        }
        .btn-filtrar {
            padding: 7px 14px; background: #ff9800; color: white;
            border: none; border-radius: 5px; font-size: 13px; font-weight: bold; cursor: pointer;
        }

        /* TOTALES */
        .card {
            background: white; border-radius: 10px; padding: 14px; margin-bottom: 10px;
        }
        .card-titulo {
            font-size: 14px; font-weight: bold; color: #666; margin-bottom: 10px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        .total-grande {
            font-size: 36px; font-weight: bold; color: #ff9800; text-align: center; padding: 10px 0;
        }
        .total-label { font-size: 13px; color: #666; text-align: center; margin-bottom: 4px; }

        .pago-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 0; border-bottom: 1px solid #f0f0f0;
        }
        .pago-row:last-child { border-bottom: none; }
        .pago-nombre { font-size: 14px; font-weight: bold; }
        .pago-monto { font-size: 16px; font-weight: bold; color: #333; }

        /* ARQUEO */
        .arqueo-card {
            background: white; border-radius: 10px; padding: 14px; margin-bottom: 10px;
        }
        .input-efectivo {
            width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px;
            font-size: 22px; text-align: center; margin: 10px 0;
        }
        .input-efectivo:focus { border-color: #ff9800; outline: none; }

        .diferencia-box {
            padding: 14px; border-radius: 8px; text-align: center; margin-top: 10px;
        }
        .diferencia-box.sobrante { background: #e8f5e9; border: 2px solid #4caf50; }
        .diferencia-box.faltante { background: #ffebee; border: 2px solid #f44336; }
        .diferencia-box.exacto   { background: #e3f2fd; border: 2px solid #2196f3; }
        .diferencia-valor { font-size: 26px; font-weight: bold; }
        .sobrante .diferencia-valor { color: #4caf50; }
        .faltante .diferencia-valor { color: #f44336; }
        .exacto   .diferencia-valor { color: #2196f3; }
        .diferencia-label { font-size: 13px; color: #555; margin-top: 4px; }

        /* LISTA ÓRDENES */
        .orden-row {
            display: flex; justify-content: space-between; align-items: flex-start;
            padding: 8px 0; border-bottom: 1px solid #f0f0f0; gap: 8px;
        }
        .orden-row:last-child { border-bottom: none; }
        .ord-num { font-weight: bold; color: #ff9800; font-size: 13px; white-space: nowrap; }
        .ord-productos { font-size: 11px; color: #666; margin-top: 2px; }
        .ord-hora { font-size: 11px; color: #999; white-space: nowrap; }
        .ord-total { font-weight: bold; font-size: 14px; white-space: nowrap; }

        .sin-ordenes { text-align: center; color: #999; padding: 20px; font-size: 14px; }
    </style>
</head>
<body>

<div class="header">
    <h1>💰 Arqueo de Caja</h1>
    <div style="display:flex;gap:8px;align-items:center">
        <a href="exportar_arqueo.php?fecha=<?php echo htmlspecialchars($fecha); ?>" class="back-btn" style="background:#4caf50">⬇ Exportar Excel</a>
        <a href="menu.php" class="back-btn">← Volver</a>
    </div>
</div>

<!-- Filtro de fecha -->
<form method="GET" action="">
    <div class="filtro-fecha">
        <label>Fecha:</label>
        <input type="date" name="fecha" value="<?php echo htmlspecialchars($fecha); ?>">
        <button type="submit" class="btn-filtrar">Ver</button>
    </div>
</form>

<!-- Total del día -->
<div class="card">
    <div class="total-label">Total vendido — <?php echo date('d/m/Y', strtotime($fecha)); ?></div>
    <div class="total-grande">₡<?php echo number_format($total_dia, 0); ?></div>
    <div style="text-align:center; color:#999; font-size:13px;"><?php echo count($ordenes_dia); ?> orden(es) completada(s)</div>
</div>

<!-- Desglose por método de pago -->
<div class="card">
    <div class="card-titulo">Desglose por método</div>
    <div class="pago-row">
        <div class="pago-nombre">💵 Efectivo</div>
        <div class="pago-monto">₡<?php echo number_format($pagos['efectivo'], 0); ?></div>
    </div>
    <div class="pago-row">
        <div class="pago-nombre">📌 SINPE</div>
        <div class="pago-monto">₡<?php echo number_format($pagos['sinpe'], 0); ?></div>
    </div>
    <div class="pago-row">
        <div class="pago-nombre">💳 Tarjeta</div>
        <div class="pago-monto">₡<?php echo number_format($pagos['tarjeta'], 0); ?></div>
    </div>
</div>

<!-- Conteo físico de efectivo -->
<div class="arqueo-card">
    <div class="card-titulo">Contar efectivo en caja</div>
    <div style="font-size:13px; color:#666; margin-bottom:4px;">
        Sistema registra: <strong>₡<?php echo number_format($pagos['efectivo'], 0); ?></strong> en efectivo
    </div>
    <input type="number" class="input-efectivo" id="efectivo_fisico"
           placeholder="0" inputmode="numeric"
           oninput="calcularDiferencia()">

    <div id="diferencia_box" style="display:none;" class="diferencia-box">
        <div class="diferencia-valor" id="diferencia_valor"></div>
        <div class="diferencia-label" id="diferencia_label"></div>
    </div>
</div>

<!-- Lista de órdenes del día -->
<div class="card">
    <div class="card-titulo">Órdenes del día</div>
    <?php if(empty($ordenes_dia)): ?>
        <div class="sin-ordenes">No hay órdenes completadas para esta fecha</div>
    <?php else: ?>
        <?php foreach($ordenes_dia as $o): ?>
            <div class="orden-row">
                <div style="flex:1; min-width:0;">
                    <div class="ord-num">
                        #<?php echo $o['numero_orden']; ?>
                        <?php if($o['nombre_cliente'] && !in_array($o['nombre_cliente'], ['Sin asignar', 'Orden Automática'])): ?>
                            — <?php echo htmlspecialchars($o['nombre_cliente']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="ord-productos"><?php echo htmlspecialchars($o['productos'] ?? ''); ?></div>
                </div>
                <div style="text-align:right;">
                    <div class="ord-total">₡<?php echo number_format($o['total'], 0); ?></div>
                    <div class="ord-hora"><?php echo date('H:i', strtotime($o['fecha_creacion'])); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
const efectivoSistema = <?php echo $pagos['efectivo']; ?>;

function calcularDiferencia() {
    const fisico = parseFloat(document.getElementById('efectivo_fisico').value) || 0;
    const diff = fisico - efectivoSistema;
    const box = document.getElementById('diferencia_box');
    const valor = document.getElementById('diferencia_valor');
    const label = document.getElementById('diferencia_label');

    box.style.display = 'block';
    box.className = 'diferencia-box';

    if(diff > 0) {
        box.classList.add('sobrante');
        valor.textContent = '+₡' + diff.toLocaleString('es-CR');
        label.textContent = 'Sobrante en caja';
    } else if(diff < 0) {
        box.classList.add('faltante');
        valor.textContent = '−₡' + Math.abs(diff).toLocaleString('es-CR');
        label.textContent = 'Faltante en caja';
    } else {
        box.classList.add('exacto');
        valor.textContent = '✓ Exacto';
        label.textContent = 'El efectivo cuadra perfectamente';
    }
}
</script>
</body>
</html>
