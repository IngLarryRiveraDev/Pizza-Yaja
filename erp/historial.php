<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

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

$busq  = trim($_GET['q'] ?? '');
$fecha = $_GET['fecha'] ?? '';
$page  = max(1, (int)($_GET['p'] ?? 1));
$limit = 20;
$off   = ($page - 1) * $limit;

$where = ["o.estado = 'completado'"];
$params = [];
if($busq) {
    $where[] = "(o.numero_orden LIKE ? OR o.nombre_cliente LIKE ?)";
    $params[] = "%{$busq}%"; $params[] = "%{$busq}%";
}
if($fecha) {
    $where[] = "DATE(o.fecha_creacion) = ?";
    $params[] = $fecha;
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$total = $conn->prepare("SELECT COUNT(*) FROM ordenes o {$whereSQL}");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$pages = max(1, ceil($totalRows / $limit));

$stmt = $conn->prepare("
  SELECT o.id, o.numero_orden, o.nombre_cliente, o.total, o.fecha_creacion,
         GROUP_CONCAT(DISTINCT p.metodo_pago ORDER BY p.metodo_pago SEPARATOR '/') AS metodo_pago
  FROM ordenes o LEFT JOIN pagos p ON p.orden_id=o.id
  {$whereSQL}
  GROUP BY o.id ORDER BY o.fecha_creacion DESC LIMIT {$limit} OFFSET {$off}
");
$stmt->execute($params);
$ordenes = $stmt->fetchAll();

// ── Log de eliminaciones
$tab = $_GET['tab'] ?? 'completadas';
// Auto-crear tabla si todavía no existe (por si entran al ERP antes que al POS)
$conn->exec("
    CREATE TABLE IF NOT EXISTS log_eliminaciones (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        orden_id       INT,
        numero_orden   INT,
        nombre_cliente VARCHAR(200),
        total          DECIMAL(10,2),
        motivo         TEXT NOT NULL,
        eliminado_por  VARCHAR(100),
        fecha          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
$eliminadas = $conn->query("SELECT * FROM log_eliminaciones ORDER BY fecha DESC LIMIT 100")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Historial · Pizza Yaja ERP</title>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">📋 Historial de Órdenes</span>
    <span class="erp-topbar-right"><?= $tab==='completadas' ? $totalRows.' resultados' : count($eliminadas).' eliminadas' ?></span>
  </div>

  <div class="erp-content">

    <!-- Tabs -->
    <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid var(--border)">
      <a href="historial.php?tab=completadas"
         style="padding:8px 18px;font-size:14px;font-weight:700;text-decoration:none;border-bottom:3px solid <?= $tab==='completadas'?'var(--orange)':'transparent' ?>;color:<?= $tab==='completadas'?'var(--orange)':'#888' ?>;margin-bottom:-2px">
        ✅ Completadas
      </a>
      <a href="historial.php?tab=eliminadas"
         style="padding:8px 18px;font-size:14px;font-weight:700;text-decoration:none;border-bottom:3px solid <?= $tab==='eliminadas'?'var(--red)':'transparent' ?>;color:<?= $tab==='eliminadas'?'var(--red)':'#888' ?>;margin-bottom:-2px">
        🗑️ Eliminadas <?php if(count($eliminadas)>0): ?><span style="background:var(--red);color:#fff;border-radius:50px;padding:1px 7px;font-size:11px;margin-left:4px"><?= count($eliminadas) ?></span><?php endif; ?>
      </a>
    </div>

    <?php if($tab === 'eliminadas'): ?>
    <!-- ── Tab Eliminadas ── -->
    <div class="ec">
      <div class="ec-head" style="color:var(--red)">🗑️ Órdenes eliminadas — registro de motivos</div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Fecha</th><th># Orden</th><th>Cliente</th><th>Total</th><th>Motivo</th><th>Eliminado por</th><th></th></tr></thead>
          <tbody>
          <?php foreach($eliminadas as $e): ?>
            <tr>
              <td style="white-space:nowrap;color:#888;font-size:12px"><?= date('d/m/Y H:i', strtotime($e['fecha'])) ?></td>
              <td><strong>#<?= $e['numero_orden'] ?></strong></td>
              <td><?= htmlspecialchars($e['nombre_cliente'] ?: '—') ?></td>
              <td style="color:var(--red);font-weight:700">₡<?= number_format($e['total'],0) ?></td>
              <td style="max-width:260px">
                <span style="background:#fff3f3;color:#c62828;padding:3px 8px;border-radius:4px;font-size:12px">
                  <?= htmlspecialchars($e['motivo']) ?>
                </span>
              </td>
              <td style="color:#888;font-size:12px"><?= htmlspecialchars($e['eliminado_por'] ?? '—') ?></td>
              <td>
                <button class="eb red" style="padding:4px 10px;font-size:12px"
                  onclick="eliminarLog(<?= $e['id'] ?>, this)">Eliminar</button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($eliminadas)): ?>
            <tr><td colspan="6" style="text-align:center;color:#aaa;padding:30px">Sin órdenes eliminadas registradas</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php else: ?>
    <!-- ── Tab Completadas ── -->
    <!-- Filtros -->
    <form method="get" class="ec" style="margin-bottom:16px">
      <input type="hidden" name="tab" value="completadas">
      <div class="ec-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div>
          <label class="ef-label">Buscar</label>
          <input class="ef" style="width:220px" name="q" value="<?= htmlspecialchars($busq) ?>" placeholder="# orden o nombre cliente">
        </div>
        <div>
          <label class="ef-label">Fecha</label>
          <input class="ef" style="width:160px" type="date" name="fecha" value="<?= $fecha ?>">
        </div>
        <button class="eb ora" type="submit">Filtrar</button>
        <a class="eb gry" href="historial.php">Limpiar</a>
      </div>
    </form>

    <!-- Tabla -->
    <div class="ec">
      <div class="ec-head">Órdenes completadas</div>
      <div class="ec-body np">
        <table class="et">
          <thead>
            <tr>
              <th>N° Orden</th>
              <th>Cliente / Mesa</th>
              <th>Total</th>
              <th>Método</th>
              <th>Fecha</th>
              <th>Detalle</th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($ordenes)): ?>
            <tr><td colspan="6" style="text-align:center;color:#aaa;padding:30px">No se encontraron órdenes</td></tr>
          <?php else: foreach($ordenes as $o): ?>
            <tr>
              <td><strong>#<?= $o['numero_orden'] ?></strong></td>
              <td><?= htmlspecialchars($o['nombre_cliente'] ?: '—') ?></td>
              <td>₡<?= number_format($o['total'],0) ?></td>
              <td>
                <?php
                  $m = strtolower($o['metodo_pago'] ?? '');
                  $cls = str_contains($m,'efec') ? 'bdg-grn' : (str_contains($m,'sinpe') ? 'bdg-blu' : (str_contains($m,'tarj') ? 'bdg-pur' : 'bdg-gry'));
                ?>
                <span class="bdg <?= $cls ?>"><?= htmlspecialchars($o['metodo_pago'] ?: '—') ?></span>
              </td>
              <td><?= date('d/m/Y H:i', strtotime($o['fecha_creacion'])) ?></td>
              <td>
                <button class="eb gry" style="padding:4px 10px;font-size:12px" onclick="verDetalle(<?= $o['id'] ?>)">Ver</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Paginación -->
    <?php if($pages > 1): ?>
    <div style="display:flex;gap:6px;justify-content:center;margin-top:12px">
      <?php for($i=1;$i<=$pages;$i++): ?>
        <a href="?tab=completadas&p=<?= $i ?>&q=<?= urlencode($busq) ?>&fecha=<?= $fecha ?>"
           class="eb <?= $i==$page?'ora':'gry' ?>" style="padding:6px 12px;min-width:36px;text-align:center">
          <?= $i ?>
        </a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

    <?php endif; // fin else tabs ?>

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
function verDetalle(id) {
  document.getElementById('mModal').style.display = 'flex';
  document.getElementById('mBody').innerHTML = 'Cargando...';
  fetch('../detalle_orden_ajax.php?orden_id=' + id)
    .then(r => r.json())
    .then(data => {
      if(!data.success) { document.getElementById('mBody').innerHTML = 'Error al cargar'; return; }
      const o = data.orden;
      document.getElementById('mTitle').textContent = 'Orden #' + o.numero_orden;
      let html = `<p><strong>Cliente:</strong> ${o.nombre_cliente || '—'}</p>
        <p><strong>Método:</strong> ${o.metodo_pago || '—'}</p>
        <p><strong>Fecha:</strong> ${o.fecha_creacion}</p>
        <hr style="margin:12px 0;border:none;border-top:1px solid #eee">
        <table style="width:100%;font-size:13px;border-collapse:collapse">
          <thead><tr style="border-bottom:2px solid #eee">
            <th style="padding:6px;text-align:left">Producto</th>
            <th style="padding:6px;text-align:center">Cant.</th>
            <th style="padding:6px;text-align:right">Subtotal</th>
          </tr></thead><tbody>`;
      (data.items || []).forEach(i => {
        html += `<tr style="border-bottom:1px solid #f5f5f5">
          <td style="padding:7px 6px">${i.nombre}</td>
          <td style="padding:7px 6px;text-align:center">${i.cantidad}</td>
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
