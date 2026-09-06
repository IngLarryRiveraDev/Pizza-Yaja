<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

// Auto-crear tabla si no existe
$conn->exec("
    CREATE TABLE IF NOT EXISTS arqueos (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id       INT NOT NULL,
        sucursal         VARCHAR(20) NOT NULL DEFAULT 'cariari',
        fecha_cierre     DATE NOT NULL,
        fisico_efectivo  DECIMAL(10,2) NOT NULL DEFAULT 0,
        fisico_sinpe     DECIMAL(10,2) NOT NULL DEFAULT 0,
        fisico_tarjeta   DECIMAL(10,2) NOT NULL DEFAULT 0,
        sistema_efectivo DECIMAL(10,2) NOT NULL DEFAULT 0,
        sistema_sinpe    DECIMAL(10,2) NOT NULL DEFAULT 0,
        sistema_tarjeta  DECIMAL(10,2) NOT NULL DEFAULT 0,
        notas            TEXT,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_usuario_fecha (usuario_id, fecha_cierre)
    )
");

if(isset($_GET['suc']) && in_array($_GET['suc'], ['cariari','guapiles','ambas'])) {
    $_SESSION['erp_sucursal'] = $_GET['suc'];
}
$suc_filtro = $_SESSION['erp_sucursal'] ?? 'ambas';

$fecha_desde = $_GET['desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');

$where  = ['1=1'];
$params = [];

if($suc_filtro !== 'ambas') {
    $where[] = "a.sucursal = ?";
    $params[] = $suc_filtro;
}
$where[] = "a.fecha_cierre BETWEEN ? AND ?";
$params[] = $fecha_desde;
$params[] = $fecha_hasta;

$whereSQL = implode(' AND ', $where);

$stmt = $conn->prepare("
    SELECT a.*, u.nombre AS cajero_nombre
    FROM arqueos a
    LEFT JOIN usuarios u ON a.usuario_id = u.id
    WHERE {$whereSQL}
    ORDER BY a.fecha_cierre DESC, a.created_at DESC
");
$stmt->execute($params);
$cierres = $stmt->fetchAll(PDO::FETCH_ASSOC);

function diff($sistema, $fisico) {
    return $fisico - $sistema;
}
function diffClass($d) {
    if($d > 0)  return 'style="color:#2e7d32;font-weight:700"';
    if($d < 0)  return 'style="color:#c62828;font-weight:700"';
    return 'style="color:#888"';
}
function fmt($n) {
    return '₡' . number_format(abs($n), 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cierres de Caja · Pizza Yaja ERP</title>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">💰 Cierres de Caja</span>
    <span class="erp-topbar-right"><?= count($cierres) ?> registro(s)</span>
  </div>

  <div class="erp-content">

    <!-- Filtros de fecha -->
    <form method="get" class="ec" style="margin-bottom:16px">
      <div class="ec-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div>
          <label class="ef-label">Desde</label>
          <input class="ef" type="date" name="desde" value="<?= $fecha_desde ?>" style="width:160px">
        </div>
        <div>
          <label class="ef-label">Hasta</label>
          <input class="ef" type="date" name="hasta" value="<?= $fecha_hasta ?>" style="width:160px">
        </div>
        <button class="eb ora" type="submit">Filtrar</button>
        <a class="eb gry" href="cierres.php">Hoy</a>
      </div>
    </form>

    <?php if(empty($cierres)): ?>
    <div class="ec">
      <div class="ec-body" style="text-align:center;padding:40px;color:#aaa">
        <div style="font-size:48px;margin-bottom:12px">📭</div>
        <p>No hay cierres registrados en este período.</p>
      </div>
    </div>
    <?php else: ?>

    <!-- Tabla de cierres -->
    <div class="ec">
      <div class="ec-head">📋 Comparación sistema vs físico</div>
      <div class="ec-body np" style="overflow-x:auto">
        <table class="et" style="min-width:900px">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Cajero</th>
              <th>Sucursal</th>
              <th colspan="2" style="text-align:center;border-left:1px solid #eee">💵 Efectivo</th>
              <th colspan="2" style="text-align:center;border-left:1px solid #eee">📌 SINPE</th>
              <th colspan="2" style="text-align:center;border-left:1px solid #eee">💳 Tarjeta</th>
              <th style="border-left:1px solid #eee">Diferencia total</th>
              <th>Notas</th>
            </tr>
            <tr style="font-size:11px;color:#aaa">
              <th></th><th></th><th></th>
              <th style="text-align:right;font-weight:600;border-left:1px solid #eee">Sistema</th>
              <th style="text-align:right;font-weight:600">Físico</th>
              <th style="text-align:right;font-weight:600;border-left:1px solid #eee">Sistema</th>
              <th style="text-align:right;font-weight:600">Físico</th>
              <th style="text-align:right;font-weight:600;border-left:1px solid #eee">Sistema</th>
              <th style="text-align:right;font-weight:600">Físico</th>
              <th style="border-left:1px solid #eee"></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($cierres as $c):
            $dEfec = diff($c['sistema_efectivo'], $c['fisico_efectivo']);
            $dSinpe= diff($c['sistema_sinpe'],    $c['fisico_sinpe']);
            $dTarj = diff($c['sistema_tarjeta'],  $c['fisico_tarjeta']);
            $dTotal= $dEfec + $dSinpe + $dTarj;
            $sucLabel = ['cariari'=>'Cariari','guapiles'=>'Guapiles'][$c['sucursal']] ?? $c['sucursal'];
          ?>
            <tr>
              <td style="white-space:nowrap;color:#888;font-size:12px">
                <?= date('d/m/Y', strtotime($c['fecha_cierre'])) ?>
                <br><span style="font-size:11px"><?= date('H:i', strtotime($c['created_at'])) ?></span>
              </td>
              <td><strong><?= htmlspecialchars($c['cajero_nombre'] ?? '—') ?></strong></td>
              <td>
                <span class="bdg <?= $c['sucursal']==='guapiles' ? 'bdg-blu' : 'bdg-org' ?>">
                  <?= $sucLabel ?>
                </span>
              </td>

              <!-- Efectivo -->
              <td style="text-align:right;border-left:1px solid #f5f5f5;color:#555"><?= fmt($c['sistema_efectivo']) ?></td>
              <td style="text-align:right"><?= fmt($c['fisico_efectivo']) ?></td>

              <!-- SINPE -->
              <td style="text-align:right;border-left:1px solid #f5f5f5;color:#555"><?= fmt($c['sistema_sinpe']) ?></td>
              <td style="text-align:right"><?= fmt($c['fisico_sinpe']) ?></td>

              <!-- Tarjeta -->
              <td style="text-align:right;border-left:1px solid #f5f5f5;color:#555"><?= fmt($c['sistema_tarjeta']) ?></td>
              <td style="text-align:right"><?= fmt($c['fisico_tarjeta']) ?></td>

              <!-- Diferencia total -->
              <td style="border-left:1px solid #f5f5f5">
                <span <?= diffClass($dTotal) ?>>
                  <?= $dTotal > 0 ? '+' : ($dTotal < 0 ? '-' : '') ?><?= fmt($dTotal) ?>
                </span>
                <?php if($dTotal != 0): ?>
                <br>
                <span style="font-size:11px;color:#aaa">
                  efec: <?= $dEfec >= 0 ? '+' : '-' ?><?= fmt($dEfec) ?>,
                  sinpe: <?= $dSinpe >= 0 ? '+' : '-' ?><?= fmt($dSinpe) ?>,
                  tarj: <?= $dTarj >= 0 ? '+' : '-' ?><?= fmt($dTarj) ?>
                </span>
                <?php endif; ?>
              </td>

              <!-- Notas -->
              <td style="max-width:200px;font-size:12px;color:#666">
                <?= $c['notas'] ? htmlspecialchars($c['notas']) : '<span style="color:#ccc">—</span>' ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Resumen del período -->
    <?php
    $resEfec  = array_sum(array_column($cierres, 'sistema_efectivo'));
    $resSinpe = array_sum(array_column($cierres, 'sistema_sinpe'));
    $resTarj  = array_sum(array_column($cierres, 'sistema_tarjeta'));
    $resTotal = $resEfec + $resSinpe + $resTarj;
    $dResEfec  = array_sum(array_map(fn($c) => diff($c['sistema_efectivo'], $c['fisico_efectivo']), $cierres));
    $dResSinpe = array_sum(array_map(fn($c) => diff($c['sistema_sinpe'],    $c['fisico_sinpe']),    $cierres));
    $dResTarj  = array_sum(array_map(fn($c) => diff($c['sistema_tarjeta'],  $c['fisico_tarjeta']),  $cierres));
    $dResTotal = $dResEfec + $dResSinpe + $dResTarj;
    ?>
    <div class="kpi-row" style="margin-top:16px">
      <div class="kpi grn">
        <div class="kpi-icon">💵</div>
        <div class="kpi-lbl">Sistema efectivo</div>
        <div class="kpi-val" style="font-size:20px"><?= fmt($resEfec) ?></div>
        <div class="kpi-sub">período</div>
      </div>
      <div class="kpi blu">
        <div class="kpi-icon">📌</div>
        <div class="kpi-lbl">Sistema SINPE</div>
        <div class="kpi-val" style="font-size:20px"><?= fmt($resSinpe) ?></div>
        <div class="kpi-sub">período</div>
      </div>
      <div class="kpi pur">
        <div class="kpi-icon">💳</div>
        <div class="kpi-lbl">Sistema tarjeta</div>
        <div class="kpi-val" style="font-size:20px"><?= fmt($resTarj) ?></div>
        <div class="kpi-sub">período</div>
      </div>
      <div class="kpi <?= $dResTotal < 0 ? 'red' : ($dResTotal > 0 ? 'grn' : 'ora') ?>">
        <div class="kpi-icon">⚖️</div>
        <div class="kpi-lbl">Diferencia acumulada</div>
        <div class="kpi-val" style="font-size:20px"><?= $dResTotal >= 0 ? '+' : '-' ?><?= fmt($dResTotal) ?></div>
        <div class="kpi-sub"><?= $dResTotal > 0 ? 'sobrante' : ($dResTotal < 0 ? 'faltante' : 'cuadra perfecto') ?></div>
      </div>
    </div>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
