<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

// ── Tablas
$conn->exec("
  CREATE TABLE IF NOT EXISTS ingredientes (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(100) NOT NULL,
    categoria      VARCHAR(50)  NOT NULL DEFAULT 'otros',
    unidad         VARCHAR(20)  NOT NULL DEFAULT 'unidades',
    stock_actual   DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock_minimo   DECIMAL(10,2) NOT NULL DEFAULT 0,
    costo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
    activo         TINYINT(1) NOT NULL DEFAULT 1,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )
");
$conn->exec("
  CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    ingrediente_id INT NOT NULL,
    tipo           ENUM('entrada','salida','ajuste') NOT NULL,
    cantidad       DECIMAL(10,2) NOT NULL,
    stock_antes    DECIMAL(10,2) NOT NULL,
    stock_despues  DECIMAL(10,2) NOT NULL,
    nota           VARCHAR(255),
    usuario_id     INT,
    fecha          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ingrediente_id) REFERENCES ingredientes(id) ON DELETE CASCADE
  )
");
// Agregar columna categoria si faltaba en instalaciones previas
try { $conn->exec("ALTER TABLE ingredientes ADD COLUMN categoria VARCHAR(50) NOT NULL DEFAULT 'otros' AFTER nombre"); } catch(PDOException $e) {}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    $accion = $d['accion'] ?? '';
    try {
        if($accion === 'crear') {
            $conn->prepare("INSERT INTO ingredientes (nombre,categoria,unidad,stock_actual,stock_minimo,costo_unitario) VALUES (?,?,?,?,?,?)")
                 ->execute([$d['nombre'], $d['categoria'] ?? 'otros', $d['unidad'], $d['stock_actual'], $d['stock_minimo'], $d['costo_unitario']]);
            echo json_encode(['success'=>true]);

        } elseif($accion === 'editar') {
            $conn->prepare("UPDATE ingredientes SET nombre=?,categoria=?,unidad=?,stock_minimo=?,costo_unitario=? WHERE id=?")
                 ->execute([$d['nombre'], $d['categoria'] ?? 'otros', $d['unidad'], $d['stock_minimo'], $d['costo_unitario'], $d['id']]);
            echo json_encode(['success'=>true]);

        } elseif($accion === 'movimiento') {
            $row = $conn->prepare("SELECT stock_actual FROM ingredientes WHERE id=?");
            $row->execute([$d['id']]);
            $ing = $row->fetch();
            if(!$ing) { echo json_encode(['success'=>false,'error'=>'No encontrado']); exit; }

            $antes = (float)$ing['stock_actual'];
            $cant  = (float)$d['cantidad'];
            if($d['tipo'] === 'entrada') {
                $despues = $antes + $cant;
            } elseif($d['tipo'] === 'salida') {
                if($cant > $antes) { echo json_encode(['success'=>false,'error'=>'Stock insuficiente (disponible: '.$antes.')']); exit; }
                $despues = $antes - $cant;
            } else {
                $despues = $cant;
                $cant = round(abs($despues - $antes), 2);
            }
            $conn->prepare("UPDATE ingredientes SET stock_actual=? WHERE id=?")->execute([$despues, $d['id']]);
            $conn->prepare("INSERT INTO movimientos_inventario (ingrediente_id,tipo,cantidad,stock_antes,stock_despues,nota,usuario_id) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$d['id'], $d['tipo'], $cant, $antes, $despues, $d['nota'] ?? null, $_SESSION['usuario_id']]);
            echo json_encode(['success'=>true, 'stock_nuevo'=>$despues]);

        } elseif($accion === 'toggle_activo') {
            $conn->prepare("UPDATE ingredientes SET activo = NOT activo WHERE id=?")->execute([$d['id']]);
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
$tab = $_GET['tab'] ?? 'stock';

// Ingredientes activos
$ingredientes = $conn->query("SELECT * FROM ingredientes WHERE activo=1 ORDER BY categoria, nombre")->fetchAll();
$inactivos    = $conn->query("SELECT * FROM ingredientes WHERE activo=0 ORDER BY nombre")->fetchAll();

// KPIs
$bajominimo  = array_filter($ingredientes, fn($i) => (float)$i['stock_actual'] <= (float)$i['stock_minimo'] && (float)$i['stock_minimo'] > 0);
$valorTotal  = array_sum(array_map(fn($i) => $i['stock_actual'] * $i['costo_unitario'], $ingredientes));
$movsHoy     = (int)$conn->query("SELECT COUNT(*) FROM movimientos_inventario WHERE DATE(fecha)=CURDATE()")->fetchColumn();

// Categorías únicas
$cats = array_unique(array_column($ingredientes, 'categoria'));
sort($cats);

// Filtros para movimientos
$fIng   = (int)($_GET['ing']   ?? 0);
$fTipo  = $_GET['tipo']  ?? '';
$fDesde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 day'));
$fHasta = $_GET['hasta'] ?? date('Y-m-d');

$mWhere = ["m.fecha BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)"];
$mParams = [$fDesde, $fHasta];
if($fIng)  { $mWhere[] = "m.ingrediente_id=?"; $mParams[] = $fIng; }
if($fTipo) { $mWhere[] = "m.tipo=?";           $mParams[] = $fTipo; }
$mSQL = "WHERE " . implode(" AND ", $mWhere);

$movimientos = $conn->prepare("
  SELECT m.*, i.nombre AS ingrediente, i.unidad
  FROM movimientos_inventario m JOIN ingredientes i ON m.ingrediente_id=i.id
  {$mSQL} ORDER BY m.fecha DESC LIMIT 200
");
$movimientos->execute($mParams);
$movs = $movimientos->fetchAll();

// Totales del período filtrado
$entradas = array_sum(array_map(fn($m) => $m['tipo']==='entrada' ? $m['cantidad'] : 0, $movs));
$salidas  = array_sum(array_map(fn($m) => $m['tipo']==='salida'  ? $m['cantidad'] : 0, $movs));

$CATS_PREDEFINIDAS = ['masas','lácteos','carnes','salsas','vegetales','bebidas','empaques','otros'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inventario · Pizza Yaja ERP</title>
<style>
.prog-bar { height:6px; background:#eee; border-radius:3px; overflow:hidden; margin-top:5px; }
.prog-fill { height:100%; border-radius:3px; transition:width .3s; }
.cat-header { background:#f8f8f8; font-size:11px; font-weight:800; letter-spacing:1px;
              text-transform:uppercase; color:#888; padding:8px 14px; border-bottom:1px solid #f0f0f0; }
</style>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">📦 Inventario</span>
    <div class="erp-topbar-right">
      <?php if(count($bajominimo) > 0): ?>
        <span class="bdg bdg-red">⚠ <?= count($bajominimo) ?> bajo mínimo</span>
      <?php endif; ?>
      <button class="eb ora" onclick="abrirModalIng()">+ Ingrediente</button>
    </div>
  </div>

  <div class="erp-content">

    <!-- KPIs -->
    <div class="kpi-row">
      <div class="kpi ora">
        <div class="kpi-icon">📦</div>
        <div class="kpi-lbl">Ingredientes</div>
        <div class="kpi-val"><?= count($ingredientes) ?></div>
        <div class="kpi-sub">activos</div>
      </div>
      <div class="kpi <?= count($bajominimo)>0?'red':'grn' ?>">
        <div class="kpi-icon">⚠</div>
        <div class="kpi-lbl">Bajo mínimo</div>
        <div class="kpi-val"><?= count($bajominimo) ?></div>
        <div class="kpi-sub"><?= count($bajominimo)>0?'necesitan reposición':'todo en orden' ?></div>
      </div>
      <div class="kpi blu">
        <div class="kpi-icon">💰</div>
        <div class="kpi-lbl">Valor en stock</div>
        <div class="kpi-val" style="font-size:20px">₡<?= number_format($valorTotal,0) ?></div>
        <div class="kpi-sub">costo total del inventario</div>
      </div>
      <div class="kpi pur">
        <div class="kpi-icon">🔄</div>
        <div class="kpi-lbl">Movimientos hoy</div>
        <div class="kpi-val"><?= $movsHoy ?></div>
        <div class="kpi-sub">entradas y salidas</div>
      </div>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid var(--border)">
      <button onclick="setTab('stock')" id="tab-stock"
        style="padding:8px 18px;border:none;background:none;font-size:14px;font-weight:700;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;color:#888">
        Stock actual
      </button>
      <button onclick="setTab('movs')" id="tab-movs"
        style="padding:8px 18px;border:none;background:none;font-size:14px;font-weight:700;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;color:#888">
        Movimientos
      </button>
    </div>

    <!-- ── TAB STOCK ─────────────────────────────────────── -->
    <div id="sec-stock">

      <?php if(count($bajominimo) > 0): ?>
      <div class="ec" style="border-left:4px solid var(--red);margin-bottom:16px">
        <div class="ec-head" style="color:var(--red)">⚠ Requieren reposición urgente</div>
        <div class="ec-body np">
          <table class="et">
            <thead><tr><th>Ingrediente</th><th>Stock actual</th><th>Mínimo</th><th>Faltante</th><th>Acción</th></tr></thead>
            <tbody>
            <?php foreach($bajominimo as $a): $falta = max(0, $a['stock_minimo'] - $a['stock_actual']); ?>
              <tr>
                <td><strong><?= htmlspecialchars($a['nombre']) ?></strong></td>
                <td style="color:var(--red);font-weight:700"><?= $a['stock_actual']+0 ?> <?= $a['unidad'] ?></td>
                <td><?= $a['stock_minimo']+0 ?> <?= $a['unidad'] ?></td>
                <td style="color:var(--red)">–<?= round($falta,2) ?> <?= $a['unidad'] ?></td>
                <td><button class="eb grn" style="padding:4px 10px;font-size:12px" onclick='abrirMov(<?= json_encode($a) ?>,"entrada")'>Reponer</button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if(empty($ingredientes)): ?>
        <div class="ec" style="text-align:center;padding:50px 20px;color:#aaa">
          <div style="font-size:48px;margin-bottom:12px">📦</div>
          <strong>Sin ingredientes</strong><br>
          <small>Agrega tu primer ingrediente con el botón superior</small>
        </div>
      <?php else: ?>
      <div class="ec">
        <div class="ec-head" style="justify-content:space-between">
          <span>Todos los ingredientes</span>
          <span style="font-size:12px;color:#888;font-weight:400">Valor total: <strong style="color:var(--text)">₡<?= number_format($valorTotal,0) ?></strong></span>
        </div>
        <div class="ec-body np">
          <table class="et" style="min-width:640px">
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Stock</th>
                <th style="min-width:120px">Nivel</th>
                <th>Mínimo</th>
                <th>Costo unit.</th>
                <th>Valor stock</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $catActual = null;
            foreach($ingredientes as $ing):
              $bajo  = (float)$ing['stock_actual'] <= (float)$ing['stock_minimo'] && (float)$ing['stock_minimo'] > 0;
              $valor = $ing['stock_actual'] * $ing['costo_unitario'];
              $pct   = $ing['stock_minimo'] > 0 ? min(100, ($ing['stock_actual'] / $ing['stock_minimo']) * 100) : 100;
              $barColor = $pct < 50 ? '#e53935' : ($pct < 100 ? '#ff9800' : '#4caf50');
              if($ing['categoria'] !== $catActual):
                $catActual = $ing['categoria'];
            ?>
              <tr>
                <td colspan="7" class="cat-header"><?= htmlspecialchars(ucfirst($catActual)) ?></td>
              </tr>
            <?php endif; ?>
              <tr>
                <td><strong><?= htmlspecialchars($ing['nombre']) ?></strong></td>
                <td>
                  <span style="font-weight:700;color:<?= $bajo?'var(--red)':'var(--text)' ?>">
                    <?= $ing['stock_actual']+0 ?>
                  </span>
                  <span style="color:#aaa;font-size:11px"> <?= $ing['unidad'] ?></span>
                </td>
                <td>
                  <div class="prog-bar">
                    <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
                  </div>
                  <span style="font-size:10px;color:#aaa"><?= round($pct) ?>%</span>
                </td>
                <td style="color:#888;font-size:13px"><?= $ing['stock_minimo']+0 ?> <?= $ing['unidad'] ?></td>
                <td style="font-size:13px">₡<?= number_format($ing['costo_unitario'],0) ?></td>
                <td style="font-size:13px;font-weight:600">₡<?= number_format($valor,0) ?></td>
                <td>
                  <div style="display:flex;gap:4px;flex-wrap:wrap">
                    <button class="eb grn" style="padding:3px 8px;font-size:11px" onclick='abrirMov(<?= json_encode($ing) ?>,"entrada")'>+</button>
                    <button class="eb red" style="padding:3px 8px;font-size:11px" onclick='abrirMov(<?= json_encode($ing) ?>,"salida")'>–</button>
                    <button class="eb blu" style="padding:3px 8px;font-size:11px" onclick='abrirMov(<?= json_encode($ing) ?>,"ajuste")'>⚖</button>
                    <button class="eb gry" style="padding:3px 8px;font-size:11px" onclick='editarIng(<?= json_encode($ing) ?>)'>✎</button>
                    <button class="eb gry" style="padding:3px 8px;font-size:11px;opacity:.7" onclick="toggleActivo(<?= $ing['id'] ?>, '<?= htmlspecialchars($ing['nombre']) ?>', false)" title="Desactivar">✕</button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if(!empty($inactivos)): ?>
      <div class="ec" style="margin-top:16px;border-left:3px solid #ddd">
        <div class="ec-head" style="cursor:pointer;user-select:none" onclick="toggleInactivos()">
          <span>Ingredientes desactivados (<?= count($inactivos) ?>)</span>
          <span id="inact-ico" style="margin-left:auto;color:#aaa">▼</span>
        </div>
        <div id="sec-inactivos" style="display:none">
          <div class="ec-body np">
            <table class="et">
              <thead><tr><th>Nombre</th><th>Categoría</th><th>Último stock</th><th>Acción</th></tr></thead>
              <tbody>
              <?php foreach($inactivos as $in): ?>
                <tr style="opacity:.6">
                  <td><?= htmlspecialchars($in['nombre']) ?></td>
                  <td style="color:#aaa"><?= htmlspecialchars($in['categoria']) ?></td>
                  <td style="color:#aaa"><?= $in['stock_actual']+0 ?> <?= $in['unidad'] ?></td>
                  <td><button class="eb grn" style="padding:4px 10px;font-size:12px" onclick="toggleActivo(<?= $in['id'] ?>, '<?= htmlspecialchars($in['nombre']) ?>', true)">Reactivar</button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /sec-stock -->

    <!-- ── TAB MOVIMIENTOS ───────────────────────────────── -->
    <div id="sec-movs" style="display:none">

      <!-- Filtros -->
      <form method="get" class="ec" style="margin-bottom:16px">
        <input type="hidden" name="tab" value="movs">
        <div class="ec-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <div>
            <label class="ef-label">Ingrediente</label>
            <select class="ef" name="ing" style="width:180px">
              <option value="">Todos</option>
              <?php foreach($ingredientes as $i): ?>
                <option value="<?= $i['id'] ?>" <?= $fIng==$i['id']?'selected':'' ?>><?= htmlspecialchars($i['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="ef-label">Tipo</label>
            <select class="ef" name="tipo" style="width:130px">
              <option value="">Todos</option>
              <option value="entrada" <?= $fTipo==='entrada'?'selected':'' ?>>📥 Entrada</option>
              <option value="salida"  <?= $fTipo==='salida' ?'selected':'' ?>>📤 Salida</option>
              <option value="ajuste"  <?= $fTipo==='ajuste' ?'selected':'' ?>>⚖ Ajuste</option>
            </select>
          </div>
          <div>
            <label class="ef-label">Desde</label>
            <input class="ef" type="date" name="desde" value="<?= $fDesde ?>" style="width:145px">
          </div>
          <div>
            <label class="ef-label">Hasta</label>
            <input class="ef" type="date" name="hasta" value="<?= $fHasta ?>" style="width:145px">
          </div>
          <button class="eb ora" type="submit">Filtrar</button>
          <a class="eb gry" href="?tab=movs">Limpiar</a>
        </div>
      </form>

      <!-- Resumen período -->
      <div class="kpi-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px">
        <div class="kpi grn">
          <div class="kpi-lbl">Total entradas</div>
          <div class="kpi-val" style="font-size:20px"><?= number_format($entradas,2) ?></div>
          <div class="kpi-sub"><?= count(array_filter($movs,fn($m)=>$m['tipo']==='entrada')) ?> movimientos</div>
        </div>
        <div class="kpi red">
          <div class="kpi-lbl">Total salidas</div>
          <div class="kpi-val" style="font-size:20px"><?= number_format($salidas,2) ?></div>
          <div class="kpi-sub"><?= count(array_filter($movs,fn($m)=>$m['tipo']==='salida')) ?> movimientos</div>
        </div>
        <div class="kpi blu">
          <div class="kpi-lbl">Ajustes</div>
          <div class="kpi-val" style="font-size:20px"><?= count(array_filter($movs,fn($m)=>$m['tipo']==='ajuste')) ?></div>
          <div class="kpi-sub">correcciones de stock</div>
        </div>
      </div>

      <div class="ec">
        <div class="ec-head">
          Movimientos
          <span style="margin-left:auto;font-size:12px;color:#888;font-weight:400"><?= count($movs) ?> registros</span>
        </div>
        <div class="ec-body np">
          <table class="et">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Ingrediente</th>
                <th>Tipo</th>
                <th>Cantidad</th>
                <th>Antes → Después</th>
                <th>Nota</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($movs as $m):
              $tcls = $m['tipo']==='entrada'?'bdg-grn':($m['tipo']==='salida'?'bdg-red':'bdg-blu');
            ?>
              <tr>
                <td style="white-space:nowrap;font-size:12px;color:#888"><?= date('d/m/Y H:i', strtotime($m['fecha'])) ?></td>
                <td><strong><?= htmlspecialchars($m['ingrediente']) ?></strong></td>
                <td><span class="bdg <?= $tcls ?>"><?= ucfirst($m['tipo']) ?></span></td>
                <td style="font-weight:600"><?= $m['cantidad']+0 ?> <span style="color:#aaa;font-size:11px"><?= $m['unidad'] ?></span></td>
                <td style="font-size:12px;color:#888;white-space:nowrap">
                  <?= $m['stock_antes']+0 ?> → <strong style="color:var(--text)"><?= $m['stock_despues']+0 ?></strong>
                </td>
                <td style="color:#888;font-size:12px;max-width:180px"><?= htmlspecialchars($m['nota'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($movs)): ?>
              <tr><td colspan="6" style="text-align:center;color:#aaa;padding:30px">Sin movimientos en este período</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /sec-movs -->

  </div>
</div>

<!-- ── Modal ingrediente ─────────────────────────────────────────────────── -->
<div id="modalIng" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;padding:12px">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;font-weight:700;font-size:15px;display:flex;justify-content:space-between;position:sticky;top:0;background:#fff;z-index:1">
      <span id="ingTitle">Nuevo ingrediente</span>
      <button onclick="cerrarModalIng()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div style="padding:20px">
      <input type="hidden" id="ingId">
      <div class="ef-group">
        <label class="ef-label">Nombre</label>
        <input class="ef" id="ingNombre" placeholder="Ej: Harina, Queso mozzarella">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="ef-group">
          <label class="ef-label">Categoría</label>
          <select class="ef" id="ingCat">
            <?php foreach($CATS_PREDEFINIDAS as $c): ?>
              <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ef-group">
          <label class="ef-label">Unidad</label>
          <input class="ef" id="ingUnidad" list="units" placeholder="kg, litros…">
          <datalist id="units">
            <option value="kg"><option value="g"><option value="litros">
            <option value="ml"><option value="unidades"><option value="cajas"><option value="bolsas">
          </datalist>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
        <div class="ef-group">
          <label class="ef-label">Stock inicial</label>
          <input class="ef" type="number" id="ingStock" min="0" step="0.01" placeholder="0">
        </div>
        <div class="ef-group">
          <label class="ef-label">Stock mínimo</label>
          <input class="ef" type="number" id="ingMinimo" min="0" step="0.01" placeholder="0">
        </div>
        <div class="ef-group">
          <label class="ef-label">Costo unit. (₡)</label>
          <input class="ef" type="number" id="ingCosto" min="0" step="1" placeholder="0">
        </div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
        <button class="eb gry" onclick="cerrarModalIng()">Cancelar</button>
        <button class="eb ora" onclick="guardarIng()">Guardar</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal movimiento ───────────────────────────────────────────────────── -->
<div id="modalMov" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;padding:12px">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:380px">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;font-weight:700;font-size:15px;display:flex;justify-content:space-between">
      <span id="movTitle">Registrar movimiento</span>
      <button onclick="cerrarModalMov()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div style="padding:20px">
      <input type="hidden" id="movId">
      <input type="hidden" id="movTipo">
      <div style="background:#f5f5f5;border-radius:8px;padding:12px;margin-bottom:14px">
        <strong id="movNombre" style="font-size:15px"></strong>
        <div style="color:#888;margin-top:4px;font-size:13px">Stock actual: <strong id="movStockActual"></strong></div>
        <div class="prog-bar" style="margin-top:8px"><div class="prog-fill" id="movProg" style="width:0%;background:#4caf50"></div></div>
      </div>
      <div class="ef-group" id="cantWrap">
        <label class="ef-label" id="cantLabel">Cantidad</label>
        <input class="ef" type="number" id="movCant" min="0" step="0.01" placeholder="0">
      </div>
      <div class="ef-group" id="ajusteWrap" style="display:none">
        <label class="ef-label">Nuevo stock total</label>
        <input class="ef" type="number" id="movAjuste" min="0" step="0.01" placeholder="0">
      </div>
      <div class="ef-group">
        <label class="ef-label">Nota (opcional)</label>
        <input class="ef" id="movNota" placeholder="Motivo del movimiento">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
        <button class="eb gry" onclick="cerrarModalMov()">Cancelar</button>
        <button class="eb ora" id="movBtn" onclick="guardarMovimiento()">Registrar</button>
      </div>
    </div>
  </div>
</div>

<script>
// ── Tabs
function setTab(t) {
  document.getElementById('sec-stock').style.display = t==='stock' ? 'block' : 'none';
  document.getElementById('sec-movs').style.display  = t==='movs'  ? 'block' : 'none';
  ['stock','movs'].forEach(id => {
    const el = document.getElementById('tab-'+id);
    el.style.borderBottomColor = 'transparent';
    el.style.color = '#888';
  });
  document.getElementById('tab-'+t).style.borderBottomColor = t==='stock' ? 'var(--orange)' : 'var(--blue)';
  document.getElementById('tab-'+t).style.color = t==='stock' ? 'var(--orange)' : 'var(--blue)';
}
setTab('<?= $tab ?>');

// ── Inactivos
function toggleInactivos() {
  const s = document.getElementById('sec-inactivos');
  const i = document.getElementById('inact-ico');
  s.style.display = s.style.display==='none' ? 'block' : 'none';
  i.textContent   = s.style.display==='none'  ? '▼' : '▲';
}

// ── Modal ingrediente
function abrirModalIng(ing) {
  document.getElementById('ingId').value     = ing ? ing.id : '';
  document.getElementById('ingNombre').value = ing ? ing.nombre : '';
  document.getElementById('ingCat').value    = ing ? (ing.categoria||'otros') : 'otros';
  document.getElementById('ingUnidad').value = ing ? ing.unidad : '';
  document.getElementById('ingStock').value  = ing ? ing.stock_actual : '';
  document.getElementById('ingMinimo').value = ing ? ing.stock_minimo : '';
  document.getElementById('ingCosto').value  = ing ? ing.costo_unitario : '';
  document.getElementById('ingStock').disabled = !!ing;
  document.getElementById('ingTitle').textContent = ing ? 'Editar ingrediente' : 'Nuevo ingrediente';
  document.getElementById('modalIng').style.display = 'flex';
  if(!ing) document.getElementById('ingNombre').focus();
}
function editarIng(ing) { abrirModalIng(ing); }
function cerrarModalIng() { document.getElementById('modalIng').style.display = 'none'; }

function guardarIng() {
  const id = document.getElementById('ingId').value;
  const body = {
    accion: id ? 'editar' : 'crear',
    id: id || undefined,
    nombre:         document.getElementById('ingNombre').value.trim(),
    categoria:      document.getElementById('ingCat').value,
    unidad:         document.getElementById('ingUnidad').value.trim(),
    stock_actual:   parseFloat(document.getElementById('ingStock').value) || 0,
    stock_minimo:   parseFloat(document.getElementById('ingMinimo').value) || 0,
    costo_unitario: parseFloat(document.getElementById('ingCosto').value) || 0,
  };
  if(!body.nombre || !body.unidad) { alert('Nombre y unidad son obligatorios'); return; }
  fetch('inventario.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body) })
    .then(r=>r.json()).then(d => { if(d.success) location.reload(); else alert('Error: '+d.error); });
}

// ── Toggle activo
function toggleActivo(id, nombre, reactivar) {
  const msg = reactivar ? `¿Reactivar "${nombre}"?` : `¿Desactivar "${nombre}"? No se eliminará, solo se ocultará del listado principal.`;
  if(!confirm(msg)) return;
  fetch('inventario.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({accion:'toggle_activo',id}) })
    .then(r=>r.json()).then(d => { if(d.success) location.reload(); else alert('Error: '+d.error); });
}

// ── Modal movimiento
function abrirMov(ing, tipo) {
  document.getElementById('movId').value  = ing.id;
  document.getElementById('movTipo').value = tipo;
  document.getElementById('movNombre').textContent = ing.nombre;

  const actual = parseFloat(ing.stock_actual) || 0;
  const minimo = parseFloat(ing.stock_minimo) || 0;
  document.getElementById('movStockActual').textContent = actual + ' ' + ing.unidad;

  const pct = minimo > 0 ? Math.min(100, (actual/minimo)*100) : 100;
  const col  = pct < 50 ? '#e53935' : (pct < 100 ? '#ff9800' : '#4caf50');
  document.getElementById('movProg').style.width      = pct + '%';
  document.getElementById('movProg').style.background = col;

  document.getElementById('movCant').value   = '';
  document.getElementById('movAjuste').value = '';
  document.getElementById('movNota').value   = '';

  const esAjuste = tipo === 'ajuste';
  document.getElementById('cantWrap').style.display   = esAjuste ? 'none' : 'block';
  document.getElementById('ajusteWrap').style.display = esAjuste ? 'block' : 'none';

  const labels = {entrada:'Cantidad a ingresar', salida:'Cantidad a retirar'};
  document.getElementById('cantLabel').textContent = labels[tipo] || '';
  const titles = {entrada:'📥 Entrada de stock', salida:'📤 Salida de stock', ajuste:'⚖ Ajuste de inventario'};
  document.getElementById('movTitle').textContent = titles[tipo];

  const btn = document.getElementById('movBtn');
  btn.className = 'eb ' + ({entrada:'grn', salida:'red', ajuste:'blu'}[tipo] || 'ora');

  document.getElementById('modalMov').style.display = 'flex';
  setTimeout(() => (document.getElementById(esAjuste?'movAjuste':'movCant')).focus(), 50);
}
function cerrarModalMov() { document.getElementById('modalMov').style.display = 'none'; }

function guardarMovimiento() {
  const tipo     = document.getElementById('movTipo').value;
  const esAjuste = tipo === 'ajuste';
  const cant     = parseFloat(esAjuste ? document.getElementById('movAjuste').value : document.getElementById('movCant').value);
  if(isNaN(cant) || cant < 0) { alert('Ingresa una cantidad válida'); return; }
  fetch('inventario.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ accion:'movimiento', id:document.getElementById('movId').value, tipo, cantidad:cant, nota:document.getElementById('movNota').value })
  }).then(r=>r.json()).then(d => {
    if(d.success) location.reload();
    else alert('Error: ' + d.error);
  });
}
</script>
</body>
</html>
