<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

function setupCombosTable($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS combos (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            categoria_id       INT NOT NULL DEFAULT 3,
            nombre             VARCHAR(100) NOT NULL,
            descripcion        TEXT,
            precio             INT NOT NULL DEFAULT 0,
            pide_pizza         TINYINT(1) NOT NULL DEFAULT 0,
            pide_segunda_pizza TINYINT(1) NOT NULL DEFAULT 0,
            pide_calzone       TINYINT(1) NOT NULL DEFAULT 0,
            pide_bebida        TINYINT(1) NOT NULL DEFAULT 0,
            precio_con_leche   INT DEFAULT NULL,
            activo             TINYINT(1) NOT NULL DEFAULT 1,
            orden              INT NOT NULL DEFAULT 0
        )
    ");
    $count = $conn->query("SELECT COUNT(*) FROM combos")->fetchColumn();
    if($count == 0) {
        $conn->exec("
            INSERT INTO combos (categoria_id, nombre, descripcion, precio, pide_pizza, pide_segunda_pizza, pide_calzone, pide_bebida, precio_con_leche, orden) VALUES
            (3, 'Combo #1',          'Pizza 16 slices + Gaseosa 2.5',                            11000, 1, 0, 0, 0, NULL, 1),
            (3, 'Combo #2',          'Pizza 12 slices + Pan de ajo + Gaseosa 1.5',               12000, 1, 0, 0, 0, NULL, 2),
            (3, 'Combo #3',          '2 Pizzas 16 slices + Gaseosa 2.5 + Calzone grande',        20000, 1, 1, 1, 0, NULL, 3),
            (4, 'Combo Personal #1', 'Pizza personal + Batido o Gaseosa',                         3200, 1, 0, 0, 1, NULL, 1),
            (4, 'Combo Personal #2', 'Lasaña + Batido o Gaseosa',                                 4200, 0, 0, 0, 1, NULL, 2),
            (4, 'Combo Personal #3', 'Calzone Jamón y Queso + Gaseosa o Batido',                  2000, 0, 0, 0, 1, 2500, 3),
            (4, 'Cajita Infantil',   'Mini pizza + Refresco 255ml + Postre + Juguete sorpresa',   4200, 0, 0, 0, 0, NULL, 4)
        ");
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    $accion = $d['accion'] ?? '';
    try {
        setupCombosTable($conn);
        if($accion === 'crear') {
            $stmt = $conn->prepare("
                INSERT INTO combos (categoria_id, nombre, descripcion, precio, pide_pizza, pide_segunda_pizza, pide_calzone, pide_bebida, precio_con_leche, orden)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                (int)$d['categoria_id'],
                trim($d['nombre']),
                trim($d['descripcion'] ?? ''),
                (int)$d['precio'],
                (int)($d['pide_pizza'] ?? 0),
                (int)($d['pide_segunda_pizza'] ?? 0),
                (int)($d['pide_calzone'] ?? 0),
                (int)($d['pide_bebida'] ?? 0),
                !empty($d['precio_con_leche']) ? (int)$d['precio_con_leche'] : null,
                (int)($d['orden'] ?? 0),
            ]);
            echo json_encode(['success'=>true, 'id'=>$conn->lastInsertId()]);

        } elseif($accion === 'editar') {
            $stmt = $conn->prepare("
                UPDATE combos SET categoria_id=?, nombre=?, descripcion=?, precio=?,
                pide_pizza=?, pide_segunda_pizza=?, pide_calzone=?, pide_bebida=?,
                precio_con_leche=?, orden=?, activo=?
                WHERE id=?
            ");
            $stmt->execute([
                (int)$d['categoria_id'],
                trim($d['nombre']),
                trim($d['descripcion'] ?? ''),
                (int)$d['precio'],
                (int)($d['pide_pizza'] ?? 0),
                (int)($d['pide_segunda_pizza'] ?? 0),
                (int)($d['pide_calzone'] ?? 0),
                (int)($d['pide_bebida'] ?? 0),
                !empty($d['precio_con_leche']) ? (int)$d['precio_con_leche'] : null,
                (int)($d['orden'] ?? 0),
                (int)($d['activo'] ?? 1),
                (int)$d['id'],
            ]);
            echo json_encode(['success'=>true]);

        } elseif($accion === 'toggle') {
            $stmt = $conn->prepare("UPDATE combos SET activo = NOT activo WHERE id=?");
            $stmt->execute([(int)$d['id']]);
            echo json_encode(['success'=>true]);

        } elseif($accion === 'eliminar') {
            $stmt = $conn->prepare("DELETE FROM combos WHERE id=?");
            $stmt->execute([(int)$d['id']]);
            echo json_encode(['success'=>true]);

        } else {
            echo json_encode(['success'=>false,'error'=>'Acción desconocida']);
        }
    } catch(PDOException $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

setupCombosTable($conn);
$todos_combos = $conn->query("SELECT * FROM combos ORDER BY categoria_id ASC, orden ASC, id ASC")->fetchAll();
$combos_reg   = array_filter($todos_combos, fn($c) => $c['categoria_id'] == 3);
$combos_pers  = array_filter($todos_combos, fn($c) => $c['categoria_id'] == 4);
$combos_promo = array_filter($todos_combos, fn($c) => $c['categoria_id'] == 11);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Combos · Pizza Yaja ERP</title>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">🍕 Combos</span>
    <div class="erp-topbar-right" style="display:flex;gap:8px">
      <button class="eb gry" onclick="abrirModal(null, 3)">+ Combo</button>
      <button class="eb ora" onclick="abrirModal(null, 4)">+ Combo Personal</button>
      <button class="eb grn" onclick="abrirModal(null, 11)">+ Promoción</button>
    </div>
  </div>

  <div class="erp-content">

    <!-- Combos regulares -->
    <div class="ec">
      <div class="ec-head">Combos</div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Nombre</th><th>Descripción</th><th>Precio</th><th>Opciones del modal</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach($combos_reg as $c): ?>
            <tr>
              <td><strong><?= htmlspecialchars($c['nombre']) ?></strong></td>
              <td style="font-size:12px;color:#888"><?= htmlspecialchars($c['descripcion']) ?></td>
              <td>₡<?= number_format($c['precio'],0) ?></td>
              <td style="font-size:11px">
                <?php
                $flags = [];
                if($c['pide_pizza'])         $flags[] = 'Sabor pizza';
                if($c['pide_segunda_pizza']) $flags[] = 'Segunda pizza';
                if($c['pide_calzone'])       $flags[] = 'Sabor calzone';
                if($c['pide_bebida'])        $flags[] = 'Bebida';
                echo $flags ? implode(' · ', $flags) : '<span style="color:#ccc">Precio fijo</span>';
                ?>
              </td>
              <td>
                <span class="bdg <?= $c['activo'] ? 'bdg-grn' : 'bdg-red' ?>">
                  <?= $c['activo'] ? 'Activo' : 'Inactivo' ?>
                </span>
              </td>
              <td style="display:flex;gap:6px;flex-wrap:wrap">
                <button class="eb gry" style="padding:5px 10px;font-size:12px"
                  onclick='abrirModal(<?= json_encode($c) ?>)'>Editar</button>
                <button class="eb <?= $c['activo'] ? 'red' : 'grn' ?>" style="padding:5px 10px;font-size:12px"
                  onclick="toggleCombo(<?= $c['id'] ?>, this)">
                  <?= $c['activo'] ? 'Desactivar' : 'Activar' ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($combos_reg)): ?>
            <tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px">Sin combos</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Combos personales -->
    <div class="ec">
      <div class="ec-head">Combos Personales</div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Nombre</th><th>Descripción</th><th>Precio</th><th>Opciones del modal</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach($combos_pers as $c): ?>
            <tr>
              <td><strong><?= htmlspecialchars($c['nombre']) ?></strong></td>
              <td style="font-size:12px;color:#888"><?= htmlspecialchars($c['descripcion']) ?></td>
              <td>
                ₡<?= number_format($c['precio'],0) ?>
                <?php if($c['precio_con_leche']): ?>
                  <div style="font-size:11px;color:#888">c/leche: ₡<?= number_format($c['precio_con_leche'],0) ?></div>
                <?php endif; ?>
              </td>
              <td style="font-size:11px">
                <?php
                $flags = [];
                if($c['pide_pizza'])         $flags[] = 'Sabor pizza';
                if($c['pide_segunda_pizza']) $flags[] = 'Segunda pizza';
                if($c['pide_calzone'])       $flags[] = 'Sabor calzone';
                if($c['pide_bebida'])        $flags[] = 'Bebida';
                echo $flags ? implode(' · ', $flags) : '<span style="color:#ccc">Precio fijo</span>';
                ?>
              </td>
              <td>
                <span class="bdg <?= $c['activo'] ? 'bdg-grn' : 'bdg-red' ?>">
                  <?= $c['activo'] ? 'Activo' : 'Inactivo' ?>
                </span>
              </td>
              <td style="display:flex;gap:6px;flex-wrap:wrap">
                <button class="eb gry" style="padding:5px 10px;font-size:12px"
                  onclick='abrirModal(<?= json_encode($c) ?>)'>Editar</button>
                <button class="eb <?= $c['activo'] ? 'red' : 'grn' ?>" style="padding:5px 10px;font-size:12px"
                  onclick="toggleCombo(<?= $c['id'] ?>, this)">
                  <?= $c['activo'] ? 'Desactivar' : 'Activar' ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($combos_pers)): ?>
            <tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px">Sin combos personales</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Promociones -->
    <div class="ec">
      <div class="ec-head">Promociones</div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Nombre</th><th>Descripción</th><th>Precio</th><th>Opciones del modal</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach($combos_promo as $c): ?>
            <tr>
              <td><strong><?= htmlspecialchars($c['nombre']) ?></strong></td>
              <td style="font-size:12px;color:#888"><?= htmlspecialchars($c['descripcion']) ?></td>
              <td>
                ₡<?= number_format($c['precio'],0) ?>
                <?php if($c['precio_con_leche']): ?>
                  <div style="font-size:11px;color:#888">c/leche: ₡<?= number_format($c['precio_con_leche'],0) ?></div>
                <?php endif; ?>
              </td>
              <td style="font-size:11px">
                <?php
                $flags = [];
                if($c['pide_pizza'])         $flags[] = 'Sabor pizza';
                if($c['pide_segunda_pizza']) $flags[] = 'Segunda pizza';
                if($c['pide_calzone'])       $flags[] = 'Sabor calzone';
                if($c['pide_bebida'])        $flags[] = 'Bebida';
                echo $flags ? implode(' · ', $flags) : '<span style="color:#ccc">Precio fijo</span>';
                ?>
              </td>
              <td>
                <span class="bdg <?= $c['activo'] ? 'bdg-grn' : 'bdg-red' ?>">
                  <?= $c['activo'] ? 'Activo' : 'Inactivo' ?>
                </span>
              </td>
              <td style="display:flex;gap:6px;flex-wrap:wrap">
                <button class="eb gry" style="padding:5px 10px;font-size:12px"
                  onclick='abrirModal(<?= json_encode($c) ?>)'>Editar</button>
                <button class="eb <?= $c['activo'] ? 'red' : 'grn' ?>" style="padding:5px 10px;font-size:12px"
                  onclick="toggleCombo(<?= $c['id'] ?>, this)">
                  <?= $c['activo'] ? 'Desactivar' : 'Activar' ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($combos_promo)): ?>
            <tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px">Sin promociones — agregá una con el botón de arriba</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <p style="font-size:12px;color:#aaa;margin-top:8px">
      <strong>Opciones del modal</strong>: controlan qué selecciona el camarero al agregar el combo a la orden (sabor de pizza, bebida, etc.).
    </p>
  </div>
</div>

<!-- Modal -->
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;overflow-y:auto;padding:16px">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:480px;margin:auto">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;font-weight:700;font-size:15px;display:flex;justify-content:space-between">
      <span id="mTitle">Nuevo combo</span>
      <button onclick="cerrar()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div style="padding:20px;display:flex;flex-direction:column;gap:12px">
      <input type="hidden" id="cId">

      <div class="ef-group" style="margin:0">
        <label class="ef-label">Categoría</label>
        <select class="ef" id="cCategoria">
          <option value="3">Combo (regular)</option>
          <option value="4">Combo Personal</option>
          <option value="11">Promoción</option>
        </select>
      </div>

      <div class="ef-group" style="margin:0">
        <label class="ef-label">Nombre</label>
        <input class="ef" id="cNombre" placeholder="Ej: Combo #1">
      </div>

      <div class="ef-group" style="margin:0">
        <label class="ef-label">Descripción</label>
        <input class="ef" id="cDesc" placeholder="Ej: Pizza 16 slices + Gaseosa 2.5">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="ef-group" style="margin:0">
          <label class="ef-label">Precio base (₡)</label>
          <input class="ef" type="number" id="cPrecio" min="0" step="100" placeholder="0">
        </div>
        <div class="ef-group" style="margin:0">
          <label class="ef-label">Precio c/ leche (₡) <span style="font-weight:400;color:#aaa">opcional</span></label>
          <input class="ef" type="number" id="cPrecioLeche" min="0" step="100" placeholder="Solo si bebida varía">
        </div>
      </div>

      <div class="ef-group" style="margin:0">
        <label class="ef-label">Orden de aparición</label>
        <input class="ef" type="number" id="cOrden" min="0" step="1" placeholder="0 = al final">
      </div>

      <div style="background:#f7f7f7;border-radius:8px;padding:14px">
        <div style="font-size:12px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px">
          ¿Qué elige el camarero al agregar este combo?
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
            <input type="checkbox" id="cPizza" style="width:16px;height:16px">
            Sabor de pizza (primera)
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
            <input type="checkbox" id="cPizza2" style="width:16px;height:16px">
            Sabor de segunda pizza
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
            <input type="checkbox" id="cCalzone" style="width:16px;height:16px">
            Sabor de calzone
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
            <input type="checkbox" id="cBebida" style="width:16px;height:16px">
            Bebida (Gaseosa / Batido)
          </label>
        </div>
      </div>

      <div id="cActivoWrap" style="display:none" class="ef-group" style="margin:0">
        <label class="ef-label">Estado</label>
        <select class="ef" id="cActivo">
          <option value="1">Activo</option>
          <option value="0">Inactivo</option>
        </select>
      </div>

      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
        <button class="eb gry" onclick="cerrar()">Cancelar</button>
        <button class="eb ora" onclick="guardar()">Guardar</button>
      </div>
    </div>
  </div>
</div>

<script>
function abrirModal(c, catDefault) {
  document.getElementById('cId').value           = c ? c.id : '';
  document.getElementById('cCategoria').value    = c ? c.categoria_id : (catDefault || 3);
  document.getElementById('cNombre').value       = c ? c.nombre : '';
  document.getElementById('cDesc').value         = c ? (c.descripcion || '') : '';
  document.getElementById('cPrecio').value       = c ? c.precio : '';
  document.getElementById('cPrecioLeche').value  = c && c.precio_con_leche ? c.precio_con_leche : '';
  document.getElementById('cOrden').value        = c ? c.orden : 0;
  document.getElementById('cPizza').checked      = c ? !!+c.pide_pizza : false;
  document.getElementById('cPizza2').checked     = c ? !!+c.pide_segunda_pizza : false;
  document.getElementById('cCalzone').checked    = c ? !!+c.pide_calzone : false;
  document.getElementById('cBebida').checked     = c ? !!+c.pide_bebida : false;
  document.getElementById('cActivo').value       = c ? c.activo : 1;
  document.getElementById('cActivoWrap').style.display = c ? 'block' : 'none';
  document.getElementById('mTitle').textContent  = c ? 'Editar combo' : 'Nuevo combo';
  document.getElementById('modal').style.display = 'flex';
}

function cerrar() { document.getElementById('modal').style.display = 'none'; }

function guardar() {
  const id = document.getElementById('cId').value;
  const nombre = document.getElementById('cNombre').value.trim();
  const precio = parseInt(document.getElementById('cPrecio').value);
  if(!nombre || !precio) { alert('Nombre y precio son obligatorios'); return; }

  const precioLeche = document.getElementById('cPrecioLeche').value;
  const body = {
    accion:             id ? 'editar' : 'crear',
    id:                 id || undefined,
    categoria_id:       parseInt(document.getElementById('cCategoria').value),
    nombre,
    descripcion:        document.getElementById('cDesc').value.trim(),
    precio,
    precio_con_leche:   precioLeche ? parseInt(precioLeche) : null,
    orden:              parseInt(document.getElementById('cOrden').value) || 0,
    pide_pizza:         document.getElementById('cPizza').checked ? 1 : 0,
    pide_segunda_pizza: document.getElementById('cPizza2').checked ? 1 : 0,
    pide_calzone:       document.getElementById('cCalzone').checked ? 1 : 0,
    pide_bebida:        document.getElementById('cBebida').checked ? 1 : 0,
    activo:             parseInt(document.getElementById('cActivo').value),
  };

  fetch('combos.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body)
  }).then(r => r.json()).then(d => {
    if(d.success) location.reload();
    else alert('Error: ' + d.error);
  });
}

function toggleCombo(id, btn) {
  fetch('combos.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({accion:'toggle', id})
  }).then(r => r.json()).then(d => {
    if(d.success) location.reload();
    else alert('Error: ' + d.error);
  });
}
</script>
</body>
</html>
