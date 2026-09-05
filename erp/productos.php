<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

function setupRecetasTable($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS recetas (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            producto_id    INT NOT NULL,
            ingrediente_id INT NOT NULL,
            cantidad       DECIMAL(10,4) NOT NULL,
            UNIQUE KEY uk_prod_ing (producto_id, ingrediente_id)
        )
    ");
}

// AJAX actions
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    $accion = $d['accion'] ?? '';
    try {
        if($accion === 'crear') {
            $stmt = $conn->prepare("INSERT INTO productos (nombre, descripcion, precio, categoria, categoria_id, disponible, activo) VALUES (?,?,?,?,?,1,1)");
            $stmt->execute([$d['nombre'], $d['descripcion'] ?? '', $d['precio'], $d['categoria'], $d['categoria_id']]);
            echo json_encode(['success'=>true, 'id'=>$conn->lastInsertId()]);

        } elseif($accion === 'editar') {
            $stmt = $conn->prepare("UPDATE productos SET nombre=?, descripcion=?, precio=?, categoria=?, categoria_id=?, disponible=? WHERE id=?");
            $stmt->execute([$d['nombre'], $d['descripcion'] ?? '', $d['precio'], $d['categoria'], $d['categoria_id'], $d['disponible'], $d['id']]);
            echo json_encode(['success'=>true]);

        } elseif($accion === 'toggle') {
            $stmt = $conn->prepare("UPDATE productos SET disponible = NOT disponible WHERE id=?");
            $stmt->execute([$d['id']]);
            echo json_encode(['success'=>true]);

        } elseif($accion === 'eliminar') {
            $stmt = $conn->prepare("DELETE FROM productos WHERE id=?");
            $stmt->execute([$d['id']]);
            echo json_encode(['success'=>true]);

        } elseif($accion === 'receta_get') {
            setupRecetasTable($conn);
            $q = $conn->prepare("
                SELECT r.ingrediente_id, r.cantidad, i.nombre, i.unidad
                FROM recetas r JOIN ingredientes i ON r.ingrediente_id=i.id
                WHERE r.producto_id=?
                ORDER BY i.nombre
            ");
            $q->execute([(int)$d['producto_id']]);
            echo json_encode(['success'=>true, 'receta'=>$q->fetchAll(PDO::FETCH_ASSOC)]);

        } elseif($accion === 'receta_guardar') {
            setupRecetasTable($conn);
            $conn->prepare("DELETE FROM recetas WHERE producto_id=?")->execute([(int)$d['producto_id']]);
            $ins = $conn->prepare("INSERT INTO recetas (producto_id, ingrediente_id, cantidad) VALUES (?,?,?)");
            foreach($d['ingredientes'] ?? [] as $ing) {
                if((float)$ing['cantidad'] > 0 && (int)$ing['ingrediente_id'] > 0) {
                    $ins->execute([(int)$d['producto_id'], (int)$ing['ingrediente_id'], (float)$ing['cantidad']]);
                }
            }
            echo json_encode(['success'=>true]);

        } else {
            echo json_encode(['success'=>false,'error'=>'Acción desconocida']);
        }
    } catch(PDOException $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

try { setupRecetasTable($conn); } catch(PDOException $e) {}

// Migraciones seguras de productos
try { $conn->exec("ALTER TABLE productos ADD COLUMN categoria  VARCHAR(50) NOT NULL DEFAULT 'General'"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE productos ADD COLUMN descripcion TEXT");                                  } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE productos ADD COLUMN disponible TINYINT(1) NOT NULL DEFAULT 1");         } catch(PDOException $e) {}

$productos  = $conn->query("SELECT * FROM productos ORDER BY categoria, nombre")->fetchAll();
$cats_tabla = $conn->query("SELECT id, nombre FROM categorias ORDER BY orden, nombre")->fetchAll();
$categorias = array_unique(array_column($productos, 'categoria'));
sort($categorias);

// Ingredientes para modal de receta — seguro si la tabla aún no existe
try {
    $ingredientes = $conn->query("SELECT id, nombre, unidad FROM ingredientes WHERE activo=1 ORDER BY nombre")->fetchAll();
} catch(PDOException $e) {
    try {
        $ingredientes = $conn->query("SELECT id, nombre, unidad FROM ingredientes ORDER BY nombre")->fetchAll();
    } catch(PDOException $e2) {
        $ingredientes = [];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Productos · Pizza Yaja ERP</title>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">🍕 Productos</span>
    <button class="eb ora erp-topbar-right" onclick="abrirModal()">+ Nuevo producto</button>
  </div>

  <div class="erp-content">
    <?php foreach($categorias as $cat): ?>
    <div class="ec">
      <div class="ec-head"><?= htmlspecialchars($cat) ?></div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Nombre</th><th>Precio</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach($productos as $p): if($p['categoria'] !== $cat) continue; ?>
            <tr data-id="<?= $p['id'] ?>">
              <td>
                <strong><?= htmlspecialchars($p['nombre']) ?></strong>
                <?php if($p['descripcion']): ?>
                  <div style="font-size:12px;color:#888"><?= htmlspecialchars($p['descripcion']) ?></div>
                <?php endif; ?>
              </td>
              <td>₡<?= number_format($p['precio'],0) ?></td>
              <td>
                <span class="bdg <?= $p['disponible'] ? 'bdg-grn' : 'bdg-red' ?>">
                  <?= $p['disponible'] ? 'Disponible' : 'No disponible' ?>
                </span>
              </td>
              <td style="display:flex;gap:6px;flex-wrap:wrap">
                <button class="eb gry" style="padding:5px 10px;font-size:12px"
                  onclick='editarProducto(<?= json_encode($p) ?>)'>Editar</button>
                <button class="eb blu" style="padding:5px 10px;font-size:12px"
                  onclick="abrirReceta(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['nombre'])) ?>')">Receta</button>
                <button class="eb <?= $p['disponible'] ? 'red' : 'grn' ?>" style="padding:5px 10px;font-size:12px"
                  onclick="toggleDisponible(<?= $p['id'] ?>, this)">
                  <?= $p['disponible'] ? 'Deshabilitar' : 'Habilitar' ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Modal producto -->
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:440px">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;font-weight:700;font-size:15px;display:flex;justify-content:space-between">
      <span id="mTitle">Nuevo producto</span>
      <button onclick="cerrar()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div style="padding:20px">
      <input type="hidden" id="pId">
      <div class="ef-group">
        <label class="ef-label">Nombre</label>
        <input class="ef" id="pNombre" placeholder="Nombre del producto">
      </div>
      <div class="ef-group">
        <label class="ef-label">Descripción</label>
        <input class="ef" id="pDesc" placeholder="Opcional">
      </div>
      <div class="ef-group">
        <label class="ef-label">Precio (₡)</label>
        <input class="ef" type="number" id="pPrecio" min="0" step="100">
      </div>
      <div class="ef-group">
        <label class="ef-label">Categoría</label>
        <select class="ef" id="pCat">
          <option value="">-- Seleccioná --</option>
          <?php foreach($cats_tabla as $c): ?>
            <option value="<?= $c['id'] ?>" data-nombre="<?= htmlspecialchars($c['nombre']) ?>">
              <?= htmlspecialchars($c['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" id="pCatNombre">
      </div>
      <div id="pDisponibleWrap" style="display:none" class="ef-group">
        <label class="ef-label">Estado</label>
        <select class="ef" id="pDisponible">
          <option value="1">Disponible</option>
          <option value="0">No disponible</option>
        </select>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
        <button class="eb gry" onclick="cerrar()">Cancelar</button>
        <button class="eb ora" id="mBtn" onclick="guardar()">Guardar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal receta -->
<div id="rModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:600;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;width:92%;max-width:540px;max-height:90vh;display:flex;flex-direction:column">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;font-weight:700;font-size:15px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
      <span id="rTitle">Receta</span>
      <button onclick="cerrarR()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>

    <div style="padding:16px 20px;overflow-y:auto;flex:1">
      <p style="font-size:13px;color:#888;margin:0 0 14px">Ingredientes que se descuentan del inventario automáticamente al completar o pagar una orden que incluya este producto.</p>

      <!-- Lista de ingredientes en la receta -->
      <div id="rRows" style="display:flex;flex-direction:column;gap:8px;min-height:40px"></div>

      <!-- Agregar nuevo ingrediente -->
      <?php if(!empty($ingredientes)): ?>
      <div style="display:flex;gap:8px;margin-top:14px;align-items:flex-end;flex-wrap:wrap">
        <div style="flex:1;min-width:160px">
          <label style="font-size:12px;color:#888;display:block;margin-bottom:4px">Ingrediente</label>
          <select class="ef" id="rIngSel" style="margin:0">
            <?php foreach($ingredientes as $ing): ?>
              <option value="<?= $ing['id'] ?>" data-unidad="<?= htmlspecialchars($ing['unidad']) ?>">
                <?= htmlspecialchars($ing['nombre']) ?> (<?= htmlspecialchars($ing['unidad']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="width:90px">
          <label style="font-size:12px;color:#888;display:block;margin-bottom:4px">Cantidad</label>
          <input class="ef" type="number" id="rCant" min="0.001" step="0.001" style="margin:0" placeholder="0">
        </div>
        <button class="eb grn" style="padding:8px 14px;font-size:13px;white-space:nowrap" onclick="addRRow()">+ Agregar</button>
      </div>
      <?php else: ?>
      <p style="color:#e67e22;font-size:13px;margin-top:12px">No hay ingredientes activos en el inventario. Agrega ingredientes primero.</p>
      <?php endif; ?>
    </div>

    <div style="padding:14px 20px;border-top:1px solid #eee;display:flex;gap:8px;justify-content:flex-end;flex-shrink:0">
      <button class="eb gry" onclick="cerrarR()">Cancelar</button>
      <button class="eb ora" onclick="guardarReceta()">Guardar receta</button>
    </div>
  </div>
</div>

<script>
// ---------- Producto modal ----------
function abrirModal(p) {
  document.getElementById('pId').value         = p ? p.id : '';
  document.getElementById('pNombre').value     = p ? p.nombre : '';
  document.getElementById('pDesc').value       = p ? (p.descripcion||'') : '';
  document.getElementById('pPrecio').value     = p ? p.precio : '';
  document.getElementById('pCat').value        = p ? (p.categoria_id || '') : '';
  document.getElementById('pDisponible').value = p ? p.disponible : 1;
  document.getElementById('pDisponibleWrap').style.display = p ? 'block' : 'none';
  document.getElementById('mTitle').textContent = p ? 'Editar producto' : 'Nuevo producto';
  document.getElementById('modal').style.display = 'flex';
}
function editarProducto(p) { abrirModal(p); }
function cerrar() { document.getElementById('modal').style.display = 'none'; }

function guardar() {
  const id   = document.getElementById('pId').value;
  const body = {
    accion:      id ? 'editar' : 'crear',
    id:          id || undefined,
    nombre:      document.getElementById('pNombre').value.trim(),
    descripcion:  document.getElementById('pDesc').value.trim(),
    precio:       parseFloat(document.getElementById('pPrecio').value),
    categoria_id: parseInt(document.getElementById('pCat').value),
    categoria:    document.getElementById('pCat').selectedOptions[0]?.dataset.nombre || '',
    disponible:   parseInt(document.getElementById('pDisponible').value),
  };
  if(!body.nombre || !body.precio || !body.categoria_id) {
    alert('Nombre, precio y categoría son obligatorios'); return;
  }
  fetch('productos.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body)
  }).then(r => r.json()).then(d => {
    if(d.success) location.reload();
    else alert('Error: ' + d.error);
  });
}

function toggleDisponible(id, btn) {
  fetch('productos.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({accion:'toggle', id})
  }).then(r => r.json()).then(d => {
    if(d.success) location.reload();
    else alert('Error: ' + d.error);
  });
}

// ---------- Receta modal ----------
let rProductoId = null;

function abrirReceta(id, nombre) {
  rProductoId = id;
  document.getElementById('rTitle').textContent = 'Receta: ' + nombre;
  document.getElementById('rRows').innerHTML = '<p style="color:#aaa;font-size:13px;text-align:center;padding:8px 0">Cargando...</p>';
  document.getElementById('rModal').style.display = 'flex';
  fetch('productos.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({accion:'receta_get', producto_id: id})
  }).then(r => r.json()).then(d => {
    const rows = document.getElementById('rRows');
    rows.innerHTML = '';
    if(d.receta && d.receta.length > 0) {
      d.receta.forEach(r => _addRRowEl(r.ingrediente_id, r.nombre, r.unidad, r.cantidad));
    } else {
      rows.innerHTML = '<p style="color:#aaa;font-size:13px;text-align:center;padding:8px 0">Sin ingredientes definidos — agrega abajo</p>';
    }
  }).catch(() => {
    document.getElementById('rRows').innerHTML = '<p style="color:#e53935;font-size:13px">Error al cargar la receta</p>';
  });
}

function _addRRowEl(ing_id, ing_nombre, unidad, cantidad) {
  const rows = document.getElementById('rRows');
  // Eliminar placeholder si existe
  const ph = rows.querySelector('p');
  if(ph) ph.remove();

  const row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:8px;align-items:center;background:#f7f7f7;padding:8px 10px;border-radius:8px';
  row.innerHTML =
    `<input type="hidden" class="r-ing-id" value="${ing_id}">` +
    `<span style="flex:1;font-size:13px">${_esc(ing_nombre)} <span style="color:#aaa;font-size:12px">(${_esc(unidad)})</span></span>` +
    `<input type="number" class="ef r-cant" value="${cantidad}" min="0.001" step="0.001"` +
    ` style="width:80px;margin:0;padding:5px 8px;font-size:13px">` +
    `<span style="font-size:12px;color:#aaa;min-width:24px">${_esc(unidad)}</span>` +
    `<button onclick="this.closest('div').remove()" style="background:none;border:none;color:#e53935;font-size:20px;cursor:pointer;line-height:1;padding:0 2px">×</button>`;
  rows.appendChild(row);
}

function addRRow() {
  const sel  = document.getElementById('rIngSel');
  const cant = parseFloat(document.getElementById('rCant').value);
  if(!sel.value || !cant || cant <= 0) {
    alert('Selecciona un ingrediente e ingresa una cantidad mayor a cero'); return;
  }
  const ing_id   = sel.value;
  const opt      = sel.options[sel.selectedIndex];
  const unidad   = opt.dataset.unidad;
  const ing_nombre = opt.text.replace(/\s*\([^)]*\)\s*$/, '');

  // Evitar duplicados
  for(const el of document.querySelectorAll('#rRows .r-ing-id')) {
    if(el.value == ing_id) { alert('Ese ingrediente ya está en la receta'); return; }
  }

  _addRRowEl(ing_id, ing_nombre, unidad, cant);
  document.getElementById('rCant').value = '';
}

function cerrarR() {
  document.getElementById('rModal').style.display = 'none';
  rProductoId = null;
}

function guardarReceta() {
  const ingredientes = [];
  document.querySelectorAll('#rRows > div').forEach(row => {
    const id  = row.querySelector('.r-ing-id')?.value;
    const qty = row.querySelector('.r-cant')?.value;
    if(id && qty && parseFloat(qty) > 0) {
      ingredientes.push({ingrediente_id: parseInt(id), cantidad: parseFloat(qty)});
    }
  });
  fetch('productos.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({accion:'receta_guardar', producto_id: rProductoId, ingredientes})
  }).then(r => r.json()).then(d => {
    if(d.success) {
      cerrarR();
      // Small toast instead of blocking alert
      const t = document.createElement('div');
      t.textContent = 'Receta guardada';
      t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#4caf50;color:#fff;padding:10px 18px;border-radius:8px;font-size:14px;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.2)';
      document.body.appendChild(t);
      setTimeout(() => t.remove(), 2500);
    } else {
      alert('Error: ' + d.error);
    }
  });
}

function _esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
