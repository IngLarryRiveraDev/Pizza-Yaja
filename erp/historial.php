<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

$conn->exec("
    CREATE TABLE IF NOT EXISTS log_eliminaciones (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        orden_id       INT,
        numero_orden   INT,
        nombre_cliente VARCHAR(200),
        total          DECIMAL(10,2),
        motivo         TEXT NOT NULL,
        eliminado_por  VARCHAR(100),
        sucursal       VARCHAR(20) NULL,
        fecha          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
try { $conn->exec("ALTER TABLE log_eliminaciones ADD COLUMN sucursal VARCHAR(20) NULL"); } catch(PDOException $e) {}

// AJAX: eliminar registro del log
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    if(($d['accion'] ?? '') === 'eliminar_log') {
        try {
            $conn->prepare("DELETE FROM log_eliminaciones WHERE id=?")->execute([(int)$d['id']]);
            echo json_encode(['success'=>true]);
        } catch(PDOException $e) {
            echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        }
    } else {
        echo json_encode(['success'=>false,'error'=>'Acción desconocida']);
    }
    exit;
}

if(isset($_GET['suc']) && in_array($_GET['suc'], ['cariari','guapiles','ambas'])) {
    $_SESSION['erp_sucursal'] = $_GET['suc'];
}
$suc_filtro = $_SESSION['erp_sucursal'] ?? 'ambas';

// Día seleccionado
$hoy   = date('Y-m-d');
$fecha = $_GET['fecha'] ?? $hoy;
$dt    = DateTime::createFromFormat('Y-m-d', $fecha);
if(!$dt || $dt->format('Y-m-d') !== $fecha) $fecha = $hoy;
if($fecha > $hoy) $fecha = $hoy;
$diaAnt = date('Y-m-d', strtotime($fecha . ' -1 day'));
$diaSig = date('Y-m-d', strtotime($fecha . ' +1 day'));
$esHoy  = $fecha === $hoy;

$tab  = ($_GET['tab'] ?? '') === 'eliminadas' ? 'eliminadas' : 'completadas';
$busq = trim($_GET['q'] ?? '');

$dias   = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses  = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$ts     = strtotime($fecha);
$fechaTexto = $dias[date('w',$ts)] . ' ' . date('j',$ts) . ' de ' . $meses[date('n',$ts)-1] . ' ' . date('Y',$ts);

$sucLabels = ['cariari'=>'Cariari','guapiles'=>'Guápiles'];

// ── Órdenes completadas del día
$where  = ["o.estado = 'completado'", "DATE(o.fecha_creacion) = ?"];
$params = [$fecha];
if($suc_filtro !== 'ambas') { $where[] = "o.sucursal = ?"; $params[] = $suc_filtro; }
$whereBase  = implode(' AND ', $where);
$paramsBase = $params;
if($busq !== '') {
    $where[] = "(o.numero_orden LIKE ? OR o.nombre_cliente LIKE ?)";
    $params[] = "%{$busq}%"; $params[] = "%{$busq}%";
}
$whereSQL = implode(' AND ', $where);

$stmt = $conn->prepare("
  SELECT o.id, o.numero_orden, o.nombre_cliente, o.total, o.fecha_creacion, o.sucursal,
         GROUP_CONCAT(DISTINCT p.metodo_pago ORDER BY p.metodo_pago SEPARATOR '/') AS metodo_pago
  FROM ordenes o LEFT JOIN pagos p ON p.orden_id=o.id
  WHERE {$whereSQL}
  GROUP BY o.id ORDER BY o.fecha_creacion ASC
");
$stmt->execute($params);
$ordenes = $stmt->fetchAll();

// Resumen del día (sin el filtro de búsqueda)
$resQ = $conn->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(o.total),0) AS t FROM ordenes o WHERE {$whereBase}");
$resQ->execute($paramsBase);
$res = $resQ->fetch();

$pagQ = $conn->prepare("
  SELECT p.metodo_pago, COALESCE(SUM(p.monto_aplicado),0) AS total
  FROM pagos p JOIN ordenes o ON p.orden_id=o.id
  WHERE {$whereBase}
  GROUP BY p.metodo_pago
");
$pagQ->execute($paramsBase);
$porMetodo = ['efectivo'=>0,'sinpe'=>0,'tarjeta'=>0];
foreach($pagQ->fetchAll() as $r) $porMetodo[$r['metodo_pago']] = (float)$r['total'];

// ── Eliminadas del día
$eWhere  = ["DATE(fecha) = ?"];
$eParams = [$fecha];
if($suc_filtro !== 'ambas') { $eWhere[] = "sucursal = ?"; $eParams[] = $suc_filtro; }
$eQ = $conn->prepare("SELECT * FROM log_eliminaciones WHERE " . implode(' AND ', $eWhere) . " ORDER BY fecha ASC");
$eQ->execute($eParams);
$eliminadas = $eQ->fetchAll();

function urlHist($cambios) {
    $p = array_merge(['tab'=>$GLOBALS['tab'], 'fecha'=>$GLOBALS['fecha']], $cambios);
    unset($p['suc']);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Historial · Pizza Yaja ERP</title>
<style>
.dia-nav { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.dia-nav .flecha { padding:8px 14px; font-size:16px; }
.dia-nav .flecha.off { opacity:.35; pointer-events:none; }
.dia-titulo { font-size:18px; font-weight:800; }
.dia-titulo small { display:block; font-size:12px; font-weight:600; color:var(--orange); }
</style>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">📋 Historial</span>
    <span class="erp-topbar-right"><?= $suc_filtro === 'ambas' ? 'Ambas sucursales' : $sucLabels[$suc_filtro] ?></span>
  </div>

  <div class="erp-content">

    <!-- Navegación por día -->
    <div class="ec" style="margin-bottom:16px">
      <div class="ec-body" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div class="dia-nav">
          <a class="eb gry flecha" href="<?= urlHist(['fecha'=>$diaAnt, 'q'=>'']) ?>" title="Día anterior">←</a>
          <div class="dia-titulo">
            <?= $fechaTexto ?>
            <?php if($esHoy): ?><small>HOY</small><?php endif; ?>
          </div>
          <a class="eb gry flecha <?= $esHoy ? 'off' : '' ?>" href="<?= urlHist(['fecha'=>$diaSig, 'q'=>'']) ?>" title="Día siguiente">→</a>
        </div>
        <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <input type="hidden" name="tab" value="<?= $tab ?>">
          <input class="ef" type="date" name="fecha" value="<?= $fecha ?>" max="<?= $hoy ?>" style="width:160px" onchange="this.form.submit()">
          <?php if(!$esHoy): ?><a class="eb ora" href="<?= urlHist(['fecha'=>$hoy, 'q'=>'']) ?>">Ir a hoy</a><?php endif; ?>
        </form>
      </div>
    </div>

    <!-- Resumen del día -->
    <div class="kpi-row">
      <div class="kpi ora">
        <div class="kpi-icon">💰</div>
        <div class="kpi-lbl">Total vendido</div>
        <div class="kpi-val" style="font-size:22px">₡<?= number_format($res['t'],0) ?></div>
        <div class="kpi-sub"><?= (int)$res['c'] ?> orden<?= (int)$res['c'] === 1 ? '' : 'es' ?> completada<?= (int)$res['c'] === 1 ? '' : 's' ?></div>
      </div>
      <div class="kpi grn">
        <div class="kpi-icon">💵</div>
        <div class="kpi-lbl">Efectivo</div>
        <div class="kpi-val" style="font-size:22px">₡<?= number_format($porMetodo['efectivo'],0) ?></div>
      </div>
      <div class="kpi blu">
        <div class="kpi-icon">📌</div>
        <div class="kpi-lbl">SINPE</div>
        <div class="kpi-val" style="font-size:22px">₡<?= number_format($porMetodo['sinpe'],0) ?></div>
      </div>
      <div class="kpi pur">
        <div class="kpi-icon">💳</div>
        <div class="kpi-lbl">Tarjeta</div>
        <div class="kpi-val" style="font-size:22px">₡<?= number_format($porMetodo['tarjeta'],0) ?></div>
      </div>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid var(--border)">
      <a href="<?= urlHist(['tab'=>'completadas']) ?>"
         style="padding:8px 18px;font-size:14px;font-weight:700;text-decoration:none;border-bottom:3px solid <?= $tab==='completadas'?'var(--orange)':'transparent' ?>;color:<?= $tab==='completadas'?'var(--orange)':'#888' ?>;margin-bottom:-2px">
        ✅ Completadas (<?= (int)$res['c'] ?>)
      </a>
      <a href="<?= urlHist(['tab'=>'eliminadas', 'q'=>'']) ?>"
         style="padding:8px 18px;font-size:14px;font-weight:700;text-decoration:none;border-bottom:3px solid <?= $tab==='eliminadas'?'var(--red)':'transparent' ?>;color:<?= $tab==='eliminadas'?'var(--red)':'#888' ?>;margin-bottom:-2px">
        🗑️ Eliminadas <?php if(count($eliminadas)>0): ?><span style="background:var(--red);color:#fff;border-radius:50px;padding:1px 7px;font-size:11px;margin-left:4px"><?= count($eliminadas) ?></span><?php endif; ?>
      </a>
    </div>

    <?php if($tab === 'eliminadas'): ?>
    <div class="ec">
      <div class="ec-head" style="color:var(--red)">🗑️ Órdenes eliminadas del día</div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Hora</th><th># Orden</th><th>Cliente</th><?php if($suc_filtro==='ambas'): ?><th>Sucursal</th><?php endif; ?><th>Total</th><th>Motivo</th><th>Eliminado por</th><th></th></tr></thead>
          <tbody>
          <?php foreach($eliminadas as $e): ?>
            <tr>
              <td style="white-space:nowrap;color:#888;font-size:12px"><?= date('H:i', strtotime($e['fecha'])) ?></td>
              <td><strong>#<?= $e['numero_orden'] ?></strong></td>
              <td><?= htmlspecialchars($e['nombre_cliente'] ?: '—') ?></td>
              <?php if($suc_filtro==='ambas'): ?>
              <td><?= $e['sucursal'] ? '<span class="bdg '.($e['sucursal']==='guapiles'?'bdg-blu':'bdg-org').'">'.($sucLabels[$e['sucursal']] ?? htmlspecialchars($e['sucursal'])).'</span>' : '<span style="color:#ccc">—</span>' ?></td>
              <?php endif; ?>
              <td style="color:var(--red);font-weight:700">₡<?= number_format($e['total'],0) ?></td>
              <td style="max-width:260px">
                <span style="background:#fff3f3;color:#c62828;padding:3px 8px;border-radius:4px;font-size:12px"><?= htmlspecialchars($e['motivo']) ?></span>
              </td>
              <td style="color:#888;font-size:12px"><?= htmlspecialchars($e['eliminado_por'] ?? '—') ?></td>
              <td><button class="eb red" style="padding:4px 10px;font-size:12px" onclick="eliminarLog(<?= $e['id'] ?>, this)">Eliminar</button></td>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($eliminadas)): ?>
            <tr><td colspan="8" style="text-align:center;color:#aaa;padding:30px">No se eliminaron órdenes este día</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php else: ?>
    <div class="ec">
      <div class="ec-head" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <span>Órdenes del día</span>
        <form method="get" style="display:flex;gap:6px;margin:0">
          <input type="hidden" name="tab" value="completadas">
          <input type="hidden" name="fecha" value="<?= $fecha ?>">
          <input class="ef" style="width:200px;padding:6px 10px;font-size:13px" name="q" value="<?= htmlspecialchars($busq) ?>" placeholder="Buscar # orden o cliente">
          <?php if($busq !== ''): ?><a class="eb gry" style="padding:6px 10px" href="<?= urlHist(['q'=>'']) ?>">✕</a><?php endif; ?>
        </form>
      </div>
      <div class="ec-body np">
        <table class="et">
          <thead>
            <tr>
              <th>Hora</th>
              <th>N° Orden</th>
              <th>Cliente / Mesa</th>
              <?php if($suc_filtro==='ambas'): ?><th>Sucursal</th><?php endif; ?>
              <th>Método</th>
              <th style="text-align:right">Total</th>
              <th>Detalle</th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($ordenes)): ?>
            <tr><td colspan="7" style="text-align:center;color:#aaa;padding:30px"><?= $busq !== '' ? 'Ninguna orden coincide con la búsqueda' : 'No hubo órdenes completadas este día' ?></td></tr>
          <?php else: foreach($ordenes as $o):
            $m = strtolower($o['metodo_pago'] ?? '');
            $cls = str_contains($m,'/') ? 'bdg-gry' : (str_contains($m,'efec') ? 'bdg-grn' : (str_contains($m,'sinpe') ? 'bdg-blu' : (str_contains($m,'tarj') ? 'bdg-pur' : 'bdg-gry')));
          ?>
            <tr>
              <td style="color:#888;font-size:12px;white-space:nowrap"><?= date('H:i', strtotime($o['fecha_creacion'])) ?></td>
              <td><strong>#<?= $o['numero_orden'] ?></strong></td>
              <td><?= htmlspecialchars($o['nombre_cliente'] ?: '—') ?></td>
              <?php if($suc_filtro==='ambas'): ?>
              <td><span class="bdg <?= $o['sucursal']==='guapiles'?'bdg-blu':'bdg-org' ?>"><?= $sucLabels[$o['sucursal']] ?? htmlspecialchars($o['sucursal'] ?? '—') ?></span></td>
              <?php endif; ?>
              <td><span class="bdg <?= $cls ?>"><?= htmlspecialchars($o['metodo_pago'] ?: '—') ?></span></td>
              <td style="text-align:right;font-weight:700">₡<?= number_format($o['total'],0) ?></td>
              <td><button class="eb gry" style="padding:4px 10px;font-size:12px" onclick="verDetalle(<?= $o['id'] ?>)">Ver</button></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- Modal detalle -->
<div id="mModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:520px;max-height:85vh;display:flex;flex-direction:column">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;font-weight:700;font-size:15px;display:flex;justify-content:space-between">
      <span id="mTitle">Detalle orden</span>
      <button onclick="cerrarModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div id="mBody" style="padding:16px 20px;overflow-y:auto;flex:1;font-size:14px">Cargando...</div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function verDetalle(id) {
  document.getElementById('mModal').style.display = 'flex';
  document.getElementById('mBody').innerHTML = 'Cargando...';
  fetch('../detalle_orden_ajax.php?orden_id=' + id)
    .then(r => r.json())
    .then(data => {
      if(!data.success) { document.getElementById('mBody').innerHTML = 'Error al cargar'; return; }
      const o = data.orden;
      document.getElementById('mTitle').textContent = 'Orden #' + o.numero_orden;
      let html = `<p><strong>Cliente:</strong> ${esc(o.nombre_cliente || '—')}</p>
        <p><strong>Método:</strong> ${esc(o.metodo_pago || '—')}</p>
        <p><strong>Fecha:</strong> ${esc(o.fecha_creacion)}</p>
        <hr style="margin:12px 0;border:none;border-top:1px solid #eee">
        <table style="width:100%;font-size:13px;border-collapse:collapse">
          <thead><tr style="border-bottom:2px solid #eee">
            <th style="padding:6px;text-align:left">Producto</th>
            <th style="padding:6px;text-align:center">Cant.</th>
            <th style="padding:6px;text-align:right">Subtotal</th>
          </tr></thead><tbody>`;
      (data.items || []).forEach(i => {
        html += `<tr style="border-bottom:1px solid #f5f5f5">
          <td style="padding:7px 6px">${esc(i.nombre)}</td>
          <td style="padding:7px 6px;text-align:center">${esc(i.cantidad)}</td>
          <td style="padding:7px 6px;text-align:right">₡${Number(i.subtotal).toLocaleString()}</td>
        </tr>`;
      });
      html += `</tbody></table>
        <div style="text-align:right;margin-top:12px;font-size:18px;font-weight:800;color:#ff6b00">
          Total: ₡${Number(o.total).toLocaleString()}
        </div>`;
      document.getElementById('mBody').innerHTML = html;
    });
}
function cerrarModal() { document.getElementById('mModal').style.display = 'none'; }

function eliminarLog(id, btn) {
  if(!confirm('¿Eliminar este registro del log? Esta acción no se puede deshacer.')) return;
  btn.disabled = true;
  fetch('historial.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({accion:'eliminar_log', id})
  }).then(r => r.json()).then(d => {
    if(d.success) btn.closest('tr').remove();
    else { alert('Error: ' + d.error); btn.disabled = false; }
  });
}
</script>
</body>
</html>
