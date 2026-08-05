<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();
$hoy  = date('Y-m-d');
$ayer = date('Y-m-d', strtotime('-1 day'));

$DONE = "estado = 'completado'";

// ── KPI: Ventas hoy vs ayer
$kVentas = $conn->prepare("SELECT COALESCE(SUM(total),0) AS v FROM ordenes WHERE DATE(fecha_creacion)=? AND {$DONE}");
$kVentas->execute([$hoy]);  $vHoy  = (float)$kVentas->fetch()['v'];
$kVentas->execute([$ayer]); $vAyer = (float)$kVentas->fetch()['v'];

// ── KPI: Órdenes hoy vs ayer
$kOrd = $conn->prepare("SELECT COUNT(*) AS c FROM ordenes WHERE DATE(fecha_creacion)=? AND {$DONE}");
$kOrd->execute([$hoy]);  $oHoy  = (int)$kOrd->fetch()['c'];
$kOrd->execute([$ayer]); $oAyer = (int)$kOrd->fetch()['c'];

// ── KPI: Ticket promedio hoy
$ticketHoy = $oHoy > 0 ? $vHoy / $oHoy : 0;

// ── KPI: Órdenes activas ahora (pendiente + en_cocina)
$kAct = $conn->query("SELECT COUNT(*) AS c FROM ordenes WHERE estado IN ('pendiente','en_cocina')")->fetch()['c'];

// ── Ventas últimos 7 días (para mini chart)
$v7 = $conn->query("
  SELECT DATE(fecha_creacion) AS d, COALESCE(SUM(total),0) AS v
  FROM ordenes WHERE fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND {$DONE}
  GROUP BY DATE(fecha_creacion) ORDER BY d ASC
")->fetchAll();

// ── Métodos de pago hoy (desde tabla pagos)
$mPago = $conn->prepare("
  SELECT p.metodo_pago, COUNT(DISTINCT p.orden_id) AS c
  FROM pagos p
  JOIN ordenes o ON p.orden_id = o.id
  WHERE DATE(o.fecha_creacion)=? AND {$DONE}
  GROUP BY p.metodo_pago
");
$mPago->execute([$hoy]);
$metodos = $mPago->fetchAll();

// ── Top 5 productos hoy
$top5 = $conn->prepare("
  SELECT d.producto_nombre AS nombre, SUM(d.cantidad) AS qty,
         SUM(d.precio_unitario * d.cantidad) AS total
  FROM detalle_orden d JOIN ordenes o ON d.orden_id = o.id
  WHERE DATE(o.fecha_creacion)=? AND {$DONE}
  GROUP BY d.producto_nombre ORDER BY qty DESC LIMIT 5
");
$top5->execute([$hoy]);
$topProd = $top5->fetchAll();

// ── Últimas 6 órdenes completadas hoy
$ultimas = $conn->prepare("
  SELECT numero_orden, nombre_cliente, total, fecha_creacion
  FROM ordenes WHERE DATE(fecha_creacion)=? AND {$DONE}
  ORDER BY fecha_creacion DESC LIMIT 6
");
$ultimas->execute([$hoy]);
$ultOrdenes = $ultimas->fetchAll();

// Helpers
function trend($now, $before) {
    if($before == 0) return $now > 0 ? '<span class="up">+∞%</span>' : '<span style="color:#888">—</span>';
    $p = (($now - $before) / $before) * 100;
    $c = $p >= 0 ? 'up' : 'dn';
    $s = $p >= 0 ? '↑' : '↓';
    return "<span class=\"{$c}\">{$s} " . number_format(abs($p), 1) . "%</span>";
}
function nfCRC($n) { return '₡' . number_format($n, 0); }

// Preparar datos para Chart.js
$chart7labels = [];
$chart7data   = [];
for($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $chart7labels[] = date('d/m', strtotime($d));
    $chart7data[$d] = 0;
}
foreach($v7 as $row) { $chart7data[$row['d']] = (float)$row['v']; }
$chartLabelsJson = json_encode(array_values($chart7labels));
$chartDataJson   = json_encode(array_values($chart7data));

$mpLabels = json_encode(array_column($metodos, 'metodo_pago'));
$mpData   = json_encode(array_column($metodos, 'c'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard · Pizza Yaja ERP</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">📊 Dashboard</span>
    <span class="erp-topbar-right"><?= date('d/m/Y') ?></span>
  </div>

  <div class="erp-content">

    <!-- KPIs -->
    <div class="kpi-row">
      <div class="kpi ora">
        <div class="kpi-icon">💰</div>
        <div class="kpi-lbl">Ventas hoy</div>
        <div class="kpi-val"><?= nfCRC($vHoy) ?></div>
        <div class="kpi-sub">vs ayer <?= trend($vHoy, $vAyer) ?></div>
      </div>
      <div class="kpi grn">
        <div class="kpi-icon">📋</div>
        <div class="kpi-lbl">Órdenes hoy</div>
        <div class="kpi-val"><?= $oHoy ?></div>
        <div class="kpi-sub">vs ayer <?= trend($oHoy, $oAyer) ?></div>
      </div>
      <div class="kpi blu">
        <div class="kpi-icon">🎯</div>
        <div class="kpi-lbl">Ticket promedio</div>
        <div class="kpi-val"><?= nfCRC($ticketHoy) ?></div>
        <div class="kpi-sub">órdenes completadas</div>
      </div>
      <div class="kpi pur">
        <div class="kpi-icon">🔥</div>
        <div class="kpi-lbl">Activas ahora</div>
        <div class="kpi-val"><?= $kAct ?></div>
        <div class="kpi-sub">pendiente + en cocina</div>
      </div>
    </div>

    <!-- Charts row -->
    <div class="eg3">
      <div class="ec nm">
        <div class="ec-head">📈 Ventas — últimos 7 días</div>
        <div class="ec-body">
          <div class="cw"><canvas id="cVentas"></canvas></div>
        </div>
      </div>
      <div class="ec nm">
        <div class="ec-head">💳 Métodos de pago (hoy)</div>
        <div class="ec-body">
          <div class="cw"><canvas id="cPagos"></canvas></div>
        </div>
      </div>
    </div>

    <!-- Tables row -->
    <div class="eg2">
      <div class="ec nm">
        <div class="ec-head">🍕 Top 5 productos hoy</div>
        <div class="ec-body np">
          <table class="et">
            <thead><tr><th>Producto</th><th>Cant.</th><th>Total</th></tr></thead>
            <tbody>
            <?php if(empty($topProd)): ?>
              <tr><td colspan="3" style="text-align:center;color:#aaa;padding:20px">Sin ventas hoy</td></tr>
            <?php else: foreach($topProd as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['nombre']) ?></td>
                <td><?= $p['qty'] ?></td>
                <td><?= nfCRC($p['total']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="ec nm">
        <div class="ec-head">🕐 Últimas órdenes completadas</div>
        <div class="ec-body np">
          <table class="et">
            <thead><tr><th>#</th><th>Cliente</th><th>Total</th></tr></thead>
            <tbody>
            <?php if(empty($ultOrdenes)): ?>
              <tr><td colspan="3" style="text-align:center;color:#aaa;padding:20px">Sin órdenes hoy</td></tr>
            <?php else: foreach($ultOrdenes as $o): ?>
              <tr>
                <td><strong>#<?= $o['numero_orden'] ?></strong></td>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <?= htmlspecialchars($o['nombre_cliente'] ?: '—') ?>
                </td>
                <td><?= nfCRC($o['total']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- /erp-content -->
</div><!-- /erp-main -->

<script>
const palette = ['#ff6b00','#ff9800','#4caf50','#2196f3','#9c27b0','#e53935','#00bcd4'];

new Chart(document.getElementById('cVentas'), {
  type: 'bar',
  data: {
    labels: <?= $chartLabelsJson ?>,
    datasets: [{
      label: 'Ventas ₡',
      data: <?= $chartDataJson ?>,
      backgroundColor: 'rgba(255,107,0,.75)',
      borderColor: '#ff6b00',
      borderWidth: 2,
      borderRadius: 6,
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
new Chart(document.getElementById('cPagos'), {
  type: 'doughnut',
  data: {
    labels: <?= $mpLabels ?>,
    datasets: [{ data: <?= $mpData ?>, backgroundColor: palette, borderWidth: 2 }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
    cutout: '62%'
  }
});
<?php else: ?>
document.getElementById('cPagos').parentElement.innerHTML = '<p style="text-align:center;color:#aaa;padding:30px 0">Sin datos hoy</p>';
<?php endif; ?>
</script>
</body>
</html>
