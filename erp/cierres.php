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
        periodo_desde    DATETIME NULL,
        periodo_hasta    DATETIME NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_usuario_fecha (usuario_id, fecha_cierre)
    )
");
try { $conn->exec("ALTER TABLE arqueos ADD COLUMN periodo_desde DATETIME NULL"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE arqueos ADD COLUMN periodo_hasta DATETIME NULL"); } catch(PDOException $e) {}
foreach(['efectivo','sinpe','tarjeta'] as $m) {
    try { $conn->exec("ALTER TABLE arqueos ADD COLUMN gastos_{$m} DECIMAL(10,2) NOT NULL DEFAULT 0"); } catch(PDOException $e) {}
}

if(isset($_GET['suc']) && in_array($_GET['suc'], ['cariari','guapiles','ambas'])) {
    $_SESSION['erp_sucursal'] = $_GET['suc'];
}
$suc_filtro = $_SESSION['erp_sucursal'] ?? 'ambas';

require_once 'includes/periodo.php';
$P = periodoActual();   // semana (lunes a domingo) o mes, igual que Ventas y Gastos

$where  = ['1=1'];
$params = [];

if($suc_filtro !== 'ambas') {
    $where[] = "a.sucursal = ?";
    $params[] = $suc_filtro;
}
$where[] = "a.fecha_cierre >= ? AND a.fecha_cierre < ?";
$params[] = $P['inicio'];
$params[] = $P['fin'];

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
function gastoCell($n) {
    return $n > 0
        ? '<span style="color:#c62828;white-space:nowrap">−' . fmt($n) . '</span>'
        : '<span style="color:#ddd">—</span>';
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

    <?php periodoNav($P); ?>

    <?php if(empty($cierres)): ?>
    <div class="ec">
      <div class="ec-body" style="text-align:center;padding:40px;color:#aaa">
        <div style="font-size:48px;margin-bottom:12px">📭</div>
        <p>No hay cierres registrados en este período.</p>
      </div>
    </div>
    <?php else: ?>

    <?php
    // ── Totales del período ──
    // Físico = lo que contaron y anotaron los camareros al cerrar
    // Esperado (sistema) = lo cobrado en el sistema menos los gastos del turno
    $suma = fn($col) => array_sum(array_map(fn($c) => (float)($c[$col] ?? 0), $cierres));
    $fisEfec  = $suma('fisico_efectivo');
    $fisSinpe = $suma('fisico_sinpe');
    $fisTarj  = $suma('fisico_tarjeta');
    $fisTotal = $fisEfec + $fisSinpe + $fisTarj;

    $resEfec  = $suma('sistema_efectivo');
    $resSinpe = $suma('sistema_sinpe');
    $resTarj  = $suma('sistema_tarjeta');
    $resTotal = $resEfec + $resSinpe + $resTarj;

    $gasEfec  = $suma('gastos_efectivo');
    $gasSinpe = $suma('gastos_sinpe');
    $gasTarj  = $suma('gastos_tarjeta');
    $resGastos = $gasEfec + $gasSinpe + $gasTarj;

    $dResEfec  = $fisEfec  - $resEfec;
    $dResSinpe = $fisSinpe - $resSinpe;
    $dResTarj  = $fisTarj  - $resTarj;
    $dResTotal = $fisTotal - $resTotal;
    $signo = fn($d) => $d > 0 ? '+' : ($d < 0 ? '-' : '');
    ?>

    <!-- Lo que contaron los camareros -->
    <div style="font-size:13px;font-weight:800;color:#555;margin:0 0 8px;text-transform:uppercase;letter-spacing:.5px">
      💵 Lo que contaron los camareros <span style="font-weight:500;text-transform:none;letter-spacing:0;color:#999">(físico, de <?= count($cierres) ?> cierre<?= count($cierres) === 1 ? '' : 's' ?>)</span>
    </div>
    <div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">
      <div class="kpi grn">
        <div class="kpi-icon">💵</div>
        <div class="kpi-lbl">Efectivo contado</div>
        <div class="kpi-val" style="font-size:20px"><?= fmt($fisEfec) ?></div>
        <div class="kpi-sub">sistema: <?= fmt($resEfec) ?></div>
      </div>
      <div class="kpi blu">
        <div class="kpi-icon">📌</div>
        <div class="kpi-lbl">SINPE contado</div>
        <div class="kpi-val" style="font-size:20px"><?= fmt($fisSinpe) ?></div>
        <div class="kpi-sub">sistema: <?= fmt($resSinpe) ?></div>
      </div>
      <div class="kpi pur">
        <div class="kpi-icon">💳</div>
        <div class="kpi-lbl">Datáfono contado</div>
        <div class="kpi-val" style="font-size:20px"><?= fmt($fisTarj) ?></div>
        <div class="kpi-sub">sistema: <?= fmt($resTarj) ?></div>
      </div>
      <div class="kpi ora">
        <div class="kpi-icon">🧮</div>
        <div class="kpi-lbl">Total físico</div>
        <div class="kpi-val" style="font-size:24px"><?= fmt($fisTotal) ?></div>
        <div class="kpi-sub">efectivo + SINPE + datáfono</div>
      </div>
    </div>

    <!-- Lo que dice el sistema -->
    <div style="font-size:13px;font-weight:800;color:#555;margin:4px 0 8px;text-transform:uppercase;letter-spacing:.5px">
      🖥️ Lo que dice el sistema
    </div>
    <div class="kpi-row" style="grid-template-columns:repeat(3,1fr)">
      <div class="kpi gry">
        <div class="kpi-icon">🖥️</div>
        <div class="kpi-lbl">Total esperado</div>
        <div class="kpi-val" style="font-size:20px"><?= fmt($resTotal) ?></div>
        <div class="kpi-sub">cobrado (<?= fmt($resTotal + $resGastos) ?>) menos gastos</div>
      </div>
      <div class="kpi red">
        <div class="kpi-icon">💸</div>
        <div class="kpi-lbl">Gastos del período</div>
        <div class="kpi-val" style="font-size:20px"><?= fmt($resGastos) ?></div>
        <div class="kpi-sub">salieron de caja</div>
      </div>
      <div class="kpi <?= $dResTotal < 0 ? 'red' : ($dResTotal > 0 ? 'grn' : 'ora') ?>">
        <div class="kpi-icon">⚖️</div>
        <div class="kpi-lbl">Diferencia (físico − sistema)</div>
        <div class="kpi-val" style="font-size:20px"><?= $signo($dResTotal) ?><?= fmt($dResTotal) ?></div>
        <div class="kpi-sub"><?= $dResTotal > 0 ? 'sobrante' : ($dResTotal < 0 ? 'faltante' : 'cuadra perfecto') ?>
          <?php if($dResTotal != 0): ?> · efec <?= $signo($dResEfec) . fmt($dResEfec) ?>, sinpe <?= $signo($dResSinpe) . fmt($dResSinpe) ?>, datáf. <?= $signo($dResTarj) . fmt($dResTarj) ?><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Tabla de cierres -->
    <div class="ec">
      <div class="ec-head">📋 Cierre por cierre: sistema vs físico</div>
      <div class="ec-body np" style="overflow-x:auto">
        <table class="et" style="min-width:900px">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Turno</th>
              <th>Cajero</th>
              <th>Sucursal</th>
              <th colspan="3" style="text-align:center;border-left:1px solid #eee">💵 Efectivo</th>
              <th colspan="3" style="text-align:center;border-left:1px solid #eee">📌 SINPE</th>
              <th colspan="3" style="text-align:center;border-left:1px solid #eee">💳 Tarjeta</th>
              <th style="text-align:right;border-left:1px solid #eee">Total físico</th>
              <th style="border-left:1px solid #eee">Diferencia total</th>
              <th>Notas</th>
            </tr>
            <tr style="font-size:11px;color:#aaa">
              <th></th><th></th><th></th><th></th>
              <th style="text-align:right;font-weight:600;border-left:1px solid #eee">Gastos</th>
              <th style="text-align:right;font-weight:600">Esperado</th>
              <th style="text-align:right;font-weight:600">Físico</th>
              <th style="text-align:right;font-weight:600;border-left:1px solid #eee">Gastos</th>
              <th style="text-align:right;font-weight:600">Esperado</th>
              <th style="text-align:right;font-weight:600">Físico</th>
              <th style="text-align:right;font-weight:600;border-left:1px solid #eee">Gastos</th>
              <th style="text-align:right;font-weight:600">Esperado</th>
              <th style="text-align:right;font-weight:600">Físico</th>
              <th style="border-left:1px solid #eee"></th>
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
            $fisFila = $c['fisico_efectivo'] + $c['fisico_sinpe'] + $c['fisico_tarjeta'];
            $sucLabel = ['cariari'=>'Cariari','guapiles'=>'Guápiles'][$c['sucursal']] ?? $c['sucursal'];
          ?>
            <tr>
              <td style="white-space:nowrap;color:#888;font-size:12px">
                <?= date('d/m/Y', strtotime($c['fecha_cierre'])) ?>
                <br><span style="font-size:11px"><?= date('H:i', strtotime($c['created_at'])) ?></span>
              </td>
              <td style="white-space:nowrap;font-size:12px">
                <?php if($c['periodo_desde'] && $c['periodo_hasta']): ?>
                  <?= date('H:i', strtotime($c['periodo_desde'])) ?> a <?= date('H:i', strtotime($c['periodo_hasta'])) ?>
                <?php else: ?>
                  <span style="color:#ccc">día completo</span>
                <?php endif; ?>
              </td>
              <td><strong><?= htmlspecialchars($c['cajero_nombre'] ?? '—') ?></strong></td>
              <td>
                <span class="bdg <?= $c['sucursal']==='guapiles' ? 'bdg-blu' : 'bdg-org' ?>">
                  <?= $sucLabel ?>
                </span>
              </td>

              <!-- Efectivo -->
              <td style="text-align:right;border-left:1px solid #f5f5f5"><?= gastoCell($c['gastos_efectivo'] ?? 0) ?></td>
              <td style="text-align:right;color:#555"><?= fmt($c['sistema_efectivo']) ?></td>
              <td style="text-align:right"><?= fmt($c['fisico_efectivo']) ?></td>

              <!-- SINPE -->
              <td style="text-align:right;border-left:1px solid #f5f5f5"><?= gastoCell($c['gastos_sinpe'] ?? 0) ?></td>
              <td style="text-align:right;color:#555"><?= fmt($c['sistema_sinpe']) ?></td>
              <td style="text-align:right"><?= fmt($c['fisico_sinpe']) ?></td>

              <!-- Tarjeta -->
              <td style="text-align:right;border-left:1px solid #f5f5f5"><?= gastoCell($c['gastos_tarjeta'] ?? 0) ?></td>
              <td style="text-align:right;color:#555"><?= fmt($c['sistema_tarjeta']) ?></td>
              <td style="text-align:right"><?= fmt($c['fisico_tarjeta']) ?></td>

              <!-- Total físico del cierre -->
              <td style="text-align:right;font-weight:700;border-left:1px solid #f5f5f5"><?= fmt($fisFila) ?></td>

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
          <tfoot>
            <tr style="background:#fff8f0;font-weight:800">
              <td colspan="4">Totales del período</td>
              <td style="text-align:right;border-left:1px solid #f5f5f5"><?= gastoCell($gasEfec) ?></td>
              <td style="text-align:right;color:#555"><?= fmt($resEfec) ?></td>
              <td style="text-align:right"><?= fmt($fisEfec) ?></td>
              <td style="text-align:right;border-left:1px solid #f5f5f5"><?= gastoCell($gasSinpe) ?></td>
              <td style="text-align:right;color:#555"><?= fmt($resSinpe) ?></td>
              <td style="text-align:right"><?= fmt($fisSinpe) ?></td>
              <td style="text-align:right;border-left:1px solid #f5f5f5"><?= gastoCell($gasTarj) ?></td>
              <td style="text-align:right;color:#555"><?= fmt($resTarj) ?></td>
              <td style="text-align:right"><?= fmt($fisTarj) ?></td>
              <td style="text-align:right;border-left:1px solid #f5f5f5;color:var(--orange)"><?= fmt($fisTotal) ?></td>
              <td style="border-left:1px solid #f5f5f5"><span <?= diffClass($dResTotal) ?>><?= $signo($dResTotal) ?><?= fmt($dResTotal) ?></span></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
