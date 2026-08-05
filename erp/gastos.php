<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

// Auto-crear tabla
$conn->exec("
  CREATE TABLE IF NOT EXISTS gastos (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    concepto    VARCHAR(200) NOT NULL,
    categoria   VARCHAR(50)  NOT NULL DEFAULT 'otros',
    monto       DECIMAL(10,2) NOT NULL,
    fecha       DATE NOT NULL,
    nota        TEXT,
    usuario_id  INT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )
");

// ── AJAX ──────────────────────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    $accion = $d['accion'] ?? '';
    try {
        if($accion === 'crear') {
            $stmt = $conn->prepare("INSERT INTO gastos (concepto,categoria,monto,fecha,nota,usuario_id) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$d['concepto'], $d['categoria'], $d['monto'], $d['fecha'], $d['nota'] ?? null, $_SESSION['usuario_id']]);
            echo json_encode(['success'=>true]);
        } elseif($accion === 'editar') {
            $stmt = $conn->prepare("UPDATE gastos SET concepto=?,categoria=?,monto=?,fecha=?,nota=? WHERE id=?");
            $stmt->execute([$d['concepto'], $d['categoria'], $d['monto'], $d['fecha'], $d['nota'] ?? null, $d['id']]);
            echo json_encode(['success'=>true]);
        } elseif($accion === 'eliminar') {
            $conn->prepare("DELETE FROM gastos WHERE id=?")->execute([$d['id']]);
            echo json_encode(['success'=>true]);
        } else {
            echo json_encode(['success'=>false,'error'=>'Acción desconocida']);
        }
    } catch(PDOException $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── DATOS ─────────────────────────────────────────────────────────────────────
$dias  = isset($_GET['dias']) && in_array($_GET['dias'], ['7','30','90']) ? (int)$_GET['dias'] : 30;
$desde = date('Y-m-d', strtotime("-{$dias} day"));
$DONE  = "estado = 'completado'";

// Gastos del período
$gastos = $conn->prepare("SELECT * FROM gastos WHERE fecha >= ? ORDER BY fecha DESC");
$gastos->execute([$desde]);
$gastosList = $gastos->fetchAll();

// Totales por categoría
$porCat = $conn->prepare("SELECT categoria, SUM(monto) AS total FROM gastos WHERE fecha >= ? GROUP BY categoria ORDER BY total DESC");
$porCat->execute([$desde]);
$porCatList = $porCat->fetchAll();

// Total gastos
$totGastos = array_sum(array_column($gastosList, 'monto'));

// Ventas del mismo período (para comparar)
$venQ = $conn->prepare("SELECT COALESCE(SUM(total),0) AS v FROM ordenes WHERE fecha_creacion >= ? AND {$DONE}");
$venQ->execute([$desde]);
$totVentas = (float)$venQ->fetch()['v'];

$margen = $totVentas > 0 ? (($totVentas - $totGastos) / $totVentas) * 100 : 0;

$CATS = ['ingredientes','servicios','personal','equipos','marketing','otros'];

$catColors = ['bdg-grn','bdg-blu','bdg-pur','bdg-org','bdg-red','bdg-gry'];
$catMap = array_combine($CATS, $catColors);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gastos · Pizza Yaja ERP</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">💸 Gastos</span>
    <div class="erp-topbar-right">
      <?php foreach(['7'=>'7 días','30'=>'30 días','90'=>'90 días'] as $v=>$l): ?>
        <a href="?dias=<?= $v ?>" class="eb <?= $dias==$v?'ora':'gry' ?>" style="padding:5px 12px;font-size:12px"><?= $l ?></a>
      <?php endforeach; ?>
      <button class="eb ora" onclick="abrirModal()">+ Registrar gasto</button>
    </div>
  </div>

  <div class="erp-content">

    <!-- KPIs -->
    <div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">
      <div class="kpi red">
        <div class="kpi-icon">💸</div>
        <div class="kpi-lbl">Total gastos</div>
        <div class="kpi-val">₡<?= number_format($totGastos,0) ?></div>
        <div class="kpi-sub">últimos <?= $dias ?> días</div>
      </div>
      <div class="kpi grn">
        <div class="kpi-icon">💰</div>
        <div class="kpi-lbl">Total ventas</div>
        <div class="kpi-val">₡<?= number_format($totVentas,0) ?></div>
        <div class="kpi-sub">mismo período</div>
      </div>
      <div class="kpi <?= ($totVentas-$totGastos)>=0?'grn':'red' ?>">
        <div class="kpi-icon">📊</div>
        <div class="kpi-lbl">Ganancia neta</div>
        <div class="kpi-val">₡<?= number_format($totVentas-$totGastos,0) ?></div>
        <div class="kpi-sub">ventas − gastos</div>
      </div>
      <div class="kpi blu">
        <div class="kpi-icon">🎯</div>
        <div class="kpi-lbl">Margen</div>
        <div class="kpi-val"><?= number_format($margen,1) ?>%</div>
        <div class="kpi-sub">sobre ventas</div>
      </div>
    </div>

    <div class="eg2" style="margin-bottom:16px">
      <!-- Gráfico por categoría -->
      <div class="ec nm">
        <div class="ec-head">📊 Gastos por categoría</div>
        <div class="ec-body">
          <?php if(!empty($porCatList)): ?>
            <div class="cw"><canvas id="cCat"></canvas></div>
          <?php else: ?>
            <p style="text-align:center;color:#aaa;padding:30px 0">Sin gastos en este período</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Resumen por categoría -->
      <div class="ec nm">
        <div class="ec-head">🗂 Desglose</div>
        <div class="ec-body np">
          <table class="et">
            <thead><tr><th>Categoría</th><th>Total</th><th>%</th></tr></thead>
            <tbody>
            <?php foreach($porCatList as $c): ?>
              <tr>
                <td><span class="bdg <?= $catMap[$c['categoria']] ?? 'bdg-gry' ?>"><?= ucfirst($c['categoria']) ?></span></td>
                <td>₡<?= number_format($c['total'],0) ?></td>
                <td style="color:#888"><?= $totGastos>0 ? number_format($c['total']/$totGastos*100,1) : 0 ?>%</td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($porCatList)): ?>
              <tr><td colspan="3" style="text-align:center;color:#aaa;padding:20px">Sin datos</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Lista de gastos -->
    <div class="ec">
      <div class="ec-head">Registro de gastos</div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Fecha</th><th>Concepto</th><th>Categoría</th><th>Monto</th><th>Nota</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach($gastosList as $g): ?>
            <tr>
              <td style="white-space:nowrap;color:#888"><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
              <td><strong><?= htmlspecialchars($g['concepto']) ?></strong></td>
              <td><span class="bdg <?= $catMap[$g['categoria']] ?? 'bdg-gry' ?>"><?= ucfirst($g['categoria']) ?></span></td>
              <td style="font-weight:700;color:var(--red)">₡<?= number_format($g['monto'],0) ?></td>
              <td style="color:#888;font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars($g['nota'] ?? '—') ?>
              </td>
              <td style="display:flex;gap:5px">
                <button class="eb gry" style="padding:4px 9px;font-size:12px"
                  onclick='editarGasto(<?= json_encode($g) ?>)'>Editar</button>
                <button class="eb red" style="padding:4px 9px;font-size:12px"
                  onclick="eliminarGasto(<?= $g['id'] ?>)">Eliminar</button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($gastosList)): ?>
            <tr><td colspan="6" style="text-align:center;color:#aaa;padding:30px">Sin gastos registrados en este período</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- Modal -->
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:440px">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;font-weight:700;font-size:15px;display:flex;justify-content:space-between">
      <span id="mTitle">Registrar gasto</span>
      <button onclick="cerrar()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div style="padding:20px">
      <input type="hidden" id="gId">
      <div class="ef-group">
        <label class="ef-label">Concepto</label>
        <input class="ef" id="gConcepto" placeholder="Ej: Compra de harina, Pago de luz">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="ef-group">
          <label class="ef-label">Categoría</label>
          <select class="ef" id="gCat">
            <?php foreach($CATS as $c): ?>
              <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ef-group">
          <label class="ef-label">Monto (₡)</label>
          <input class="ef" type="number" id="gMonto" min="0" step="100" placeholder="0">
        </div>
      </div>
      <div class="ef-group">
        <label class="ef-label">Fecha</label>
        <input class="ef" type="date" id="gFecha" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="ef-group">
        <label class="ef-label">Nota (opcional)</label>
        <textarea class="ef" id="gNota" rows="2" placeholder="Detalles adicionales" style="resize:vertical"></textarea>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
        <button class="eb gry" onclick="cerrar()">Cancelar</button>
        <button class="eb red" onclick="guardar()">Guardar gasto</button>
      </div>
    </div>
  </div>
</div>

<script>
function abrirModal(g) {
  document.getElementById('gId').value      = g ? g.id : '';
  document.getElementById('gConcepto').value = g ? g.concepto : '';
  document.getElementById('gCat').value     = g ? g.categoria : 'otros';
  document.getElementById('gMonto').value   = g ? g.monto : '';
  document.getElementById('gFecha').value   = g ? g.fecha : '<?= date('Y-m-d') ?>';
  document.getElementById('gNota').value    = g ? (g.nota||'') : '';
  document.getElementById('mTitle').textContent = g ? 'Editar gasto' : 'Registrar gasto';
  document.getElementById('modal').style.display = 'flex';
}
function editarGasto(g) { abrirModal(g); }
function cerrar() { document.getElementById('modal').style.display = 'none'; }

function guardar() {
  const id = document.getElementById('gId').value;
  const body = {
    accion: id ? 'editar' : 'crear',
    id: id || undefined,
    concepto:  document.getElementById('gConcepto').value.trim(),
    categoria: document.getElementById('gCat').value,
    monto:     parseFloat(document.getElementById('gMonto').value),
    fecha:     document.getElementById('gFecha').value,
    nota:      document.getElementById('gNota').value.trim(),
  };
  if(!body.concepto || !body.monto || !body.fecha) { alert('Concepto, monto y fecha son obligatorios'); return; }
  fetch('gastos.php', {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)
  }).then(r=>r.json()).then(d => {
    if(d.success) location.reload();
    else alert('Error: '+d.error);
  });
}

function eliminarGasto(id) {
  if(!confirm('¿Eliminar este gasto?')) return;
  fetch('gastos.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({accion:'eliminar', id})
  }).then(r=>r.json()).then(d => {
    if(d.success) location.reload();
    else alert('Error: '+d.error);
  });
}

<?php if(!empty($porCatList)): ?>
new Chart(document.getElementById('cCat'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_map(fn($c)=>ucfirst($c['categoria']), $porCatList)) ?>,
    datasets: [{
      data: <?= json_encode(array_column($porCatList,'total')) ?>,
      backgroundColor: ['#e53935','#2196f3','#9c27b0','#ff9800','#4caf50','#888'],
      borderWidth: 2
    }]
  },
  options: {
    responsive:true, maintainAspectRatio:false,
    plugins: { legend: { position:'bottom', labels:{ font:{size:11} } } },
    cutout: '60%'
  }
});
<?php endif; ?>
</script>
</body>
</html>
