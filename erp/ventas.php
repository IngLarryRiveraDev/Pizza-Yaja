<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

$dias  = isset($_GET['dias']) && in_array($_GET['dias'], ['7','30','90']) ? (int)$_GET['dias'] : 30;
$desde = date('Y-m-d', strtotime("-{$dias} day"));
$DONE  = "estado = 'completado'";

// Ventas por día
$vDia = $conn->prepare("
  SELECT DATE(fecha_creacion) AS d, COUNT(*) AS ordenes, SUM(total) AS total
  FROM ordenes WHERE fecha_creacion >= ? AND {$DONE}
  GROUP BY DATE(fecha_creacion) ORDER BY d ASC
");
$vDia->execute([$desde]);
$ventasDia = $vDia->fetchAll();

// Métodos de pago (desde tabla pagos)
$mPago = $conn->prepare("
  SELECT p.metodo_pago, COUNT(DISTINCT p.orden_id) AS ordenes, SUM(p.monto_aplicado) AS total
  FROM pagos p JOIN ordenes o ON p.orden_id=o.id
  WHERE o.fecha_creacion >= ? AND {$DONE}
  GROUP BY p.metodo_pago ORDER BY total DESC
");
$mPago->execute([$desde]);
$metodos = $mPago->fetchAll();

// Top productos
$topQ = $conn->prepare("
  SELECT d.producto_nombre AS nombre, SUM(d.cantidad) AS qty,
         SUM(d.precio_unitario * d.cantidad) AS total
  FROM detalle_orden d JOIN ordenes o ON d.orden_id=o.id
  WHERE o.fecha_creacion >= ? AND {$DONE}
  GROUP BY d.producto_nombre ORDER BY qty DESC LIMIT 10
");
$topQ->execute([$desde]);
$topProd = $topQ->fetchAll();

// Totales
$totQ = $conn->prepare("SELECT COUNT(*) AS o, COALESCE(SUM(total),0) AS v FROM ordenes WHERE fecha_creacion >= ? AND {$DONE}");
$totQ->execute([$desde]);
$totales = $totQ->fetch();

// Horas pico
$horasQ = $conn->prepare("
  SELECT HOUR(fecha_creacion) AS h, COUNT(*) AS c
  FROM ordenes WHERE fecha_creacion >= ? AND {$DONE}
  GROUP BY HOUR(fecha_creacion) ORDER BY h ASC
");
$horasQ->execute([$desde]);
$horas = $horasQ->fetchAll();

$horaLabels = array_map(fn($r) => str_pad($r['h'],2,'0',STR_PAD_LEFT).':00', $horas);
$horaData   = array_column($horas, 'c');

// Rellenar todos los días del período aunque no tengan ventas
$dMap = [];
foreach($ventasDia as $r) $dMap[$r['d']] = (float)$r['total'];
$dLabels = []; $dData = [];
for($i = $dias - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $dLabels[] = date('d/m', strtotime($d));
    $dData[]   = $dMap[$d] ?? 0;
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
    <div class="erp-topbar-right" style="display:flex;gap:6px">
      <?php foreach(['7'=>'7 días','30'=>'30 días','90'=>'90 días'] as $v=>$l): ?>
        <a href="?dias=<?= $v ?>" class="eb <?= $dias==$v?'ora':'gry' ?>" style="padding:5px 12px;font-size:12px"><?= $l ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="erp-content">
    <!-- KPI summary -->
    <div class="kpi-row" style="grid-template-columns:repeat(3,1fr)">
      <div class="kpi ora">
        <div class="kpi-icon">💰</div>
        <div class="kpi-lbl">Total ventas</div>
        <div class="kpi-val">₡<?= number_format($totales['v'],0) ?></div>
        <div class="kpi-sub">últimos <?= $dias ?> días</div>
      </div>
      <div class="kpi grn">
        <div class="kpi-icon">📋</div>
        <div class="kpi-lbl">Órdenes completadas</div>
        <div class="kpi-val"><?= $totales['o'] ?></div>
        <div class="kpi-sub">últimos <?= $dias ?> días</div>
      </div>
      <div class="kpi blu">
        <div class="kpi-icon">🎯</div>
        <div class="kpi-lbl">Ticket promedio</div>
        <div class="kpi-val">₡<?= $totales['o']>0 ? number_format($totales['v']/$totales['o'],0) : 0 ?></div>
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
