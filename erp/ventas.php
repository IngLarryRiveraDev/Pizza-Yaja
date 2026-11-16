<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

if(isset($_GET['suc']) && in_array($_GET['suc'], ['cariari','guapiles','ambas'])) {
    $_SESSION['erp_sucursal'] = $_GET['suc'];
}
$suc_filtro = $_SESSION['erp_sucursal'] ?? 'ambas';
$SUC  = $suc_filtro !== 'ambas' ? "AND o.sucursal = '{$suc_filtro}'" : '';
$SUCB = $suc_filtro !== 'ambas' ? "AND sucursal = '{$suc_filtro}'" : '';

require_once 'includes/periodo.php';
$P     = periodoActual();               // semana (lun–dom) o mes de calendario
$RANGO = "fecha_creacion >= ? AND fecha_creacion < ?";
$R     = [$P['inicio'], $P['fin']];
$DONE  = "estado = 'completado'";

// Ventas por día
$vDia = $conn->prepare("
  SELECT DATE(fecha_creacion) AS d, COUNT(*) AS ordenes, SUM(total) AS total
  FROM ordenes WHERE {$RANGO} AND {$DONE} {$SUCB}
  GROUP BY DATE(fecha_creacion) ORDER BY d ASC
");
$vDia->execute($R);
$ventasDia = [];
foreach($vDia->fetchAll() as $r) $ventasDia[$r['d']] = ['ordenes' => (int)$r['ordenes'], 'total' => (float)$r['total']];

// Métodos de pago (desde tabla pagos)
$mPago = $conn->prepare("
  SELECT p.metodo_pago, COUNT(DISTINCT p.orden_id) AS ordenes, SUM(p.monto_aplicado) AS total
  FROM pagos p JOIN ordenes o ON p.orden_id=o.id
  WHERE o.{$RANGO} AND {$DONE} {$SUC}
  GROUP BY p.metodo_pago ORDER BY total DESC
");
$mPago->execute($R);
$metodos = $mPago->fetchAll();

// Top productos
$topQ = $conn->prepare("
  SELECT d.producto_nombre AS nombre, SUM(d.cantidad) AS qty,
         SUM(d.precio_unitario * d.cantidad) AS total
  FROM detalle_orden d JOIN ordenes o ON d.orden_id=o.id
  WHERE o.{$RANGO} AND {$DONE} {$SUC}
  GROUP BY d.producto_nombre ORDER BY qty DESC LIMIT 10
");
$topQ->execute($R);
$topProd = $topQ->fetchAll();

// Totales del período y del anterior (para comparar)
$totQ = $conn->prepare("SELECT COUNT(*) AS o, COALESCE(SUM(total),0) AS v FROM ordenes WHERE {$RANGO} AND {$DONE} {$SUCB}");
$totQ->execute($R);
$totales = $totQ->fetch();
$totQ->execute([$P['antInicio'], $P['antFin']]);
$totalesAnt = $totQ->fetch();
$ticket = $totales['o'] > 0 ? $totales['v'] / $totales['o'] : 0;

// Horas pico
$horasQ = $conn->prepare("
  SELECT HOUR(fecha_creacion) AS h, COUNT(*) AS c
  FROM ordenes WHERE {$RANGO} AND {$DONE} {$SUCB}
  GROUP BY HOUR(fecha_creacion) ORDER BY h ASC
");
$horasQ->execute($R);
$horas = $horasQ->fetchAll();

$horaLabels = array_map(fn($r) => str_pad($r['h'],2,'0',STR_PAD_LEFT).':00', $horas);
$horaData   = array_column($horas, 'c');

// Todos los días del período, aunque no tengan ventas
$dLabels = []; $dData = [];
foreach($P['dias'] as $d => $info) {
    $dLabels[] = $info['etiqueta'];
    $dData[]   = $ventasDia[$d]['total'] ?? 0;
}

// Vista de mes: subtotales por semana (lunes a domingo, recortadas al mes)
$porSemana = [];
foreach($P['semanas'] as $s) {
    $o = 0; $t = 0;
    foreach($ventasDia as $d => $v) if($d >= $s['desde'] && $d < $s['hasta']) { $o += $v['ordenes']; $t += $v['total']; }
    $porSemana[] = $s + ['ordenes' => $o, 'total' => $t];
}

$mLabels = array_column($metodos, 'metodo_pago');
$mData   = array_column($metodos, 'total');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ventas · Pizza Yaja ERP</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">📈 Ventas</span>
  </div>

  <div class="erp-content">
    <?php periodoNav($P); ?>

    <!-- KPI summary -->
    <div class="kpi-row" style="grid-template-columns:repeat(3,1fr)">
      <div class="kpi ora">
        <div class="kpi-icon">💰</div>
        <div class="kpi-lbl">Total ventas</div>
        <div class="kpi-val">₡<?= number_format($totales['v'],0) ?></div>
        <div class="kpi-sub"><?= perVariacion($totales['v'], $totalesAnt['v'], $P) ?></div>
      </div>
      <div class="kpi grn">
        <div class="kpi-icon">📋</div>
        <div class="kpi-lbl">Órdenes completadas</div>
        <div class="kpi-val"><?= $totales['o'] ?></div>
        <div class="kpi-sub"><?= perVariacion($totales['o'], $totalesAnt['o'], $P) ?></div>
      </div>
      <div class="kpi blu">
        <div class="kpi-icon">🧾</div>
        <div class="kpi-lbl">Ticket promedio</div>
        <div class="kpi-val">₡<?= number_format($ticket,0) ?></div>
        <div class="kpi-sub">por orden</div>
      </div>
    </div>

    <!-- Charts -->
    <div class="ec">
      <div class="ec-head">📊 Ventas por día</div>
      <div class="ec-body">
        <div class="cw lg"><canvas id="cDia"></canvas></div>
      </div>
    </div>

    <?php if($P['vista'] === 'semana'): ?>
    <!-- Semana: día por día -->
    <div class="ec">
      <div class="ec-head">📅 Día por día</div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Día</th><th style="text-align:right">Órdenes</th><th style="text-align:right">Total</th></tr></thead>
          <tbody>
          <?php foreach($P['dias'] as $d => $info): $v = $ventasDia[$d] ?? ['ordenes'=>0,'total'=>0]; ?>
            <tr style="<?= $info['futuro'] ? 'color:#bbb' : '' ?>">
              <td><strong><?= $info['etiqueta'] ?></strong> <span style="color:#999;font-size:12px"><?= perFechaCorta($d) ?></span></td>
              <td style="text-align:right"><?= $info['futuro'] ? '—' : $v['ordenes'] ?></td>
              <td style="text-align:right;font-weight:700"><?= $info['futuro'] ? '—' : '₡' . number_format($v['total'],0) ?></td>
            </tr>
          <?php endforeach; ?>
            <tr style="background:#fff8f0">
              <td><strong>Total de la semana</strong></td>
              <td style="text-align:right;font-weight:800"><?= $totales['o'] ?></td>
              <td style="text-align:right;font-weight:800;color:var(--orange)">₡<?= number_format($totales['v'],0) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php else: ?>
    <!-- Mes: semana por semana (lunes a domingo) -->
    <div class="ec">
      <div class="ec-head">📅 Semana por semana <span style="font-size:12px;color:#888;font-weight:400;margin-left:6px">de lunes a domingo</span></div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Semana</th><th style="text-align:right">Órdenes</th><th style="text-align:right">Total</th><th></th></tr></thead>
          <tbody>
          <?php foreach($porSemana as $i => $s): ?>
            <tr style="<?= $s['desde'] > $P['hoy'] ? 'color:#bbb' : '' ?>">
              <td>
                <strong>Semana <?= $i + 1 ?></strong> <span style="color:#999;font-size:12px"><?= $s['etiqueta'] ?></span>
                <?php if(!$s['completa']): ?><span style="font-size:11px;color:#999"> (parte de la semana)</span><?php endif; ?>
              </td>
              <td style="text-align:right"><?= $s['desde'] > $P['hoy'] ? '—' : $s['ordenes'] ?></td>
              <td style="text-align:right;font-weight:700"><?= $s['desde'] > $P['hoy'] ? '—' : '₡' . number_format($s['total'],0) ?></td>
              <td style="text-align:right"><?php if($s['desde'] <= $P['hoy']): ?><a class="eb gry" style="padding:3px 10px;font-size:12px" href="<?= perUrl($P, ['vista'=>'semana', 'ref'=>$s['desde']]) ?>">Ver semana</a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
            <tr style="background:#fff8f0">
              <td><strong>Total del mes</strong></td>
              <td style="text-align:right;font-weight:800"><?= $totales['o'] ?></td>
              <td style="text-align:right;font-weight:800;color:var(--orange)">₡<?= number_format($totales['v'],0) ?></td>
              <td></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="eg2">
      <div class="ec nm">
        <div class="ec-head">💳 Ventas por método de pago</div>
        <div class="ec-body">
          <div class="cw"><canvas id="cMetodos"></canvas></div>
        </div>
      </div>
      <div class="ec nm">
        <div class="ec-head">⏰ Horas pico</div>
        <div class="ec-body">
          <div class="cw"><canvas id="cHoras"></canvas></div>
        </div>
      </div>
    </div>

    <!-- Top productos -->
    <div class="ec">
      <div class="ec-head">🍕 Top 10 productos más vendidos</div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>#</th><th>Producto</th><th>Cantidad</th><th>Total generado</th></tr></thead>
          <tbody>
          <?php foreach($topProd as $i=>$p): ?>
            <tr>
              <td><strong><?= $i+1 ?></strong></td>
              <td><?= htmlspecialchars($p['nombre']) ?></td>
              <td><?= $p['qty'] ?></td>
              <td>₡<?= number_format($p['total'],0) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($topProd)): ?>
            <tr><td colspan="4" style="text-align:center;color:#aaa;padding:20px">Sin datos en este período</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<script>
const pal = ['#ff6b00','#ff9800','#4caf50','#2196f3','#9c27b0','#e53935'];

new Chart(document.getElementById('cDia'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($dLabels) ?>,
    datasets: [{
      label: 'Ventas ₡', data: <?= json_encode($dData) ?>,
      backgroundColor: 'rgba(255,107,0,.7)', borderColor: '#ff6b00',
      borderWidth: 2, borderRadius: 6
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false } },
      y: { ticks: { callback: v => '₡' + v.toLocaleString() }, grid: { color: '#f0f0f0' } }
    }
  }
});

<?php if(!empty($metodos)): ?>
new Chart(document.getElementById('cMetodos'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($mLabels) ?>,
    datasets: [{ data: <?= json_encode($mData) ?>, backgroundColor: pal, borderWidth: 2 }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' } }, cutout: '60%'
  }
});
<?php else: ?>
document.getElementById('cMetodos').parentElement.innerHTML = '<p style="text-align:center;color:#aaa;padding:30px 0">Sin datos</p>';
<?php endif; ?>

<?php if(!empty($horas)): ?>
new Chart(document.getElementById('cHoras'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($horaLabels) ?>,
    datasets: [{
      label: 'Órdenes', data: <?= json_encode($horaData) ?>,
      backgroundColor: 'rgba(33,150,243,.7)', borderColor: '#2196f3',
      borderWidth: 2, borderRadius: 5
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { grid: { display: false } }, y: { ticks: { stepSize: 1 } } }
  }
});
<?php else: ?>
document.getElementById('cHoras').parentElement.innerHTML = '<p style="text-align:center;color:#aaa;padding:30px 0">Sin datos</p>';
<?php endif; ?>
</script>
</body>
</html>
