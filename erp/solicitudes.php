<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
require_once '../solicitudes_fn.php';
$conn = getConnection();
setupSolicitudes($conn);

// ── AJAX: responder una solicitud ────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    $id     = (int)($d['id'] ?? 0);
    $accion = $d['accion'] ?? '';
    $resp   = trim($d['respuesta'] ?? '');

    try {
        $stmt = $conn->prepare("SELECT * FROM solicitudes WHERE id = ? AND estado = 'pendiente'");
        $stmt->execute([$id]);
        $sol = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$sol) { echo json_encode(['success'=>false,'error'=>'La solicitud ya fue respondida']); exit; }

        if($accion === 'aprobar') {
            if($sol['tipo'] === 'eliminacion') {
                $uq = $conn->prepare("SELECT nombre FROM usuarios WHERE id = ?");
                $uq->execute([$sol['usuario_id']]);
                $pidio = $uq->fetchColumn() ?: 'Camarero';
                eliminarOrdenAprobada($conn, (int)$sol['orden_id'], $sol['motivo'], $pidio);
            }
            $conn->prepare("
                UPDATE solicitudes SET estado='aprobada', respuesta=?, respondido_por=?, respondido_at=NOW() WHERE id=?
            ")->execute([$resp !== '' ? $resp : null, $_SESSION['usuario_id'], $id]);

        } elseif($accion === 'rechazar') {
            $conn->prepare("
                UPDATE solicitudes SET estado='rechazada', respuesta=?, respondido_por=?, respondido_at=NOW() WHERE id=?
            ")->execute([$resp !== '' ? $resp : null, $_SESSION['usuario_id'], $id]);

        } else {
            echo json_encode(['success'=>false,'error'=>'Acción desconocida']); exit;
        }

        echo json_encode(['success'=>true]);
    } catch(PDOException $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

if(isset($_GET['suc']) && in_array($_GET['suc'], ['cariari','guapiles','ambas'])) {
    $_SESSION['erp_sucursal'] = $_GET['suc'];
}
$suc_filtro = $_SESSION['erp_sucursal'] ?? 'ambas';

$where  = [];
$params = [];
if($suc_filtro !== 'ambas') { $where[] = "s.sucursal = ?"; $params[] = $suc_filtro; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$q = $conn->prepare("
    SELECT s.*, u.nombre AS pidio, r.nombre AS respondio
    FROM solicitudes s
    LEFT JOIN usuarios u ON s.usuario_id = u.id
    LEFT JOIN usuarios r ON s.respondido_por = r.id
    {$whereSQL}
    ORDER BY (s.estado = 'pendiente') DESC, s.created_at DESC
    LIMIT 100
");
$q->execute($params);
$solicitudes = $q->fetchAll(PDO::FETCH_ASSOC);

$pendientes = array_filter($solicitudes, fn($s) => $s['estado'] === 'pendiente');

// Detalle de las órdenes que se quieren eliminar, para decidir con criterio
$detalles = [];
$ids = array_filter(array_map(fn($s) => $s['tipo']==='eliminacion' && $s['estado']==='pendiente' ? (int)$s['orden_id'] : null, $solicitudes));
if($ids) {
    $in = implode(',', $ids);
    foreach($conn->query("SELECT orden_id, producto_nombre, cantidad, precio_unitario FROM detalle_orden WHERE orden_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $d2) {
        $detalles[(int)$d2['orden_id']][] = $d2;
    }
    foreach($conn->query("SELECT id, total FROM ordenes WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $o2) {
        $totales[(int)$o2['id']] = $o2['total'];
    }
}

$sucLabels = ['cariari'=>'Cariari','guapiles'=>'Guápiles'];
$tipoLabel = ['eliminacion'=>'🗑️ Eliminar orden', 'cierre'=>'💰 Hacer cierre'];
$estBadge  = ['pendiente'=>'bdg-org','aprobada'=>'bdg-grn','rechazada'=>'bdg-red','usada'=>'bdg-gry'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Solicitudes · Pizza Yaja ERP</title>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">🔔 Solicitudes</span>
    <span class="erp-topbar-right">
      <?php if(count($pendientes) > 0): ?>
        <span class="bdg bdg-org"><?= count($pendientes) ?> esperando respuesta</span>
      <?php else: ?>
        <span style="color:#888;font-size:13px">Sin pendientes</span>
      <?php endif; ?>
    </span>
  </div>

  <div class="erp-content">

    <?php if(empty($solicitudes)): ?>
      <div class="ec"><div class="ec-body" style="text-align:center;padding:40px;color:#aaa">
        <div style="font-size:48px;margin-bottom:12px">📭</div>
        <p>No hay solicitudes.</p>
      </div></div>
    <?php else: ?>

    <?php foreach($solicitudes as $s):
      $esPend = $s['estado'] === 'pendiente';
    ?>
      <div class="ec" style="<?= $esPend ? 'border-left:4px solid var(--orange)' : 'opacity:.75' ?>">
        <div class="ec-head" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
          <span>
            <?= $tipoLabel[$s['tipo']] ?? $s['tipo'] ?>
            <?php if($s['numero_orden']): ?>
              <strong style="margin-left:6px">#<?= (int)$s['numero_orden'] ?></strong>
            <?php endif; ?>
          </span>
          <span style="display:flex;gap:6px;align-items:center">
            <span class="bdg <?= $s['sucursal']==='guapiles'?'bdg-blu':'bdg-org' ?>"><?= $sucLabels[$s['sucursal']] ?? $s['sucursal'] ?></span>
            <span class="bdg <?= $estBadge[$s['estado']] ?? 'bdg-gry' ?>"><?= ucfirst($s['estado']) ?></span>
          </span>
        </div>
        <div class="ec-body">
          <div style="font-size:13px;color:#555;margin-bottom:8px">
            Pidió <strong><?= htmlspecialchars($s['pidio'] ?? '—') ?></strong>
            el <?= date('d/m/Y H:i', strtotime($s['created_at'])) ?>
            <?php if($s['respondido_at']): ?>
              — respondido por <strong><?= htmlspecialchars($s['respondio'] ?? '—') ?></strong>
              el <?= date('d/m/Y H:i', strtotime($s['respondido_at'])) ?>
            <?php endif; ?>
          </div>

          <?php if($s['motivo']): ?>
          <div style="background:#fff8f0;border-left:3px solid var(--orange);padding:10px;border-radius:4px;margin-bottom:10px">
            <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px">Motivo</div>
            <div style="font-size:14px"><?= htmlspecialchars($s['motivo']) ?></div>
          </div>
          <?php endif; ?>

          <?php if($esPend && $s['tipo']==='eliminacion' && !empty($detalles[(int)$s['orden_id']])): ?>
          <div style="background:#fafafa;border-radius:6px;padding:10px;margin-bottom:10px">
            <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Qué lleva la orden</div>
            <?php foreach($detalles[(int)$s['orden_id']] as $it): ?>
              <div style="font-size:13px;display:flex;justify-content:space-between;padding:2px 0">
                <span><?= (int)$it['cantidad'] ?>x <?= htmlspecialchars($it['producto_nombre']) ?></span>
                <span style="color:#888">₡<?= number_format($it['precio_unitario'] * $it['cantidad'], 0) ?></span>
              </div>
            <?php endforeach; ?>
            <div style="border-top:1px solid #eee;margin-top:6px;padding-top:6px;text-align:right;font-weight:700;color:var(--red)">
              Total: ₡<?= number_format($totales[(int)$s['orden_id']] ?? 0, 0) ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if($s['respuesta']): ?>
            <div style="font-size:13px;color:#555"><strong>Respuesta:</strong> <?= htmlspecialchars($s['respuesta']) ?></div>
          <?php endif; ?>

          <?php if($esPend): ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px">
            <input class="ef" id="resp_<?= $s['id'] ?>" placeholder="Nota para el camarero (opcional)" style="flex:1;min-width:200px">
            <button class="eb grn" onclick="responder(<?= $s['id'] ?>, 'aprobar', <?= $s['tipo']==='eliminacion' ? 'true' : 'false' ?>)">✓ Aprobar</button>
            <button class="eb red" onclick="responder(<?= $s['id'] ?>, 'rechazar', false)">✕ Rechazar</button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>

  </div>
</div>

<script>
function responder(id, accion, borra) {
  if(accion === 'aprobar' && borra && !confirm('¿Aprobar? La orden se elimina y no se puede recuperar.')) return;
  if(accion === 'rechazar' && !confirm('¿Rechazar esta solicitud?')) return;

  fetch('solicitudes.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      id, accion,
      respuesta: document.getElementById('resp_' + id).value.trim()
    })
  }).then(r => r.json()).then(d => {
    if(d.success) location.reload();
    else alert('Error: ' + d.error);
  });
}
</script>
</body>
</html>
