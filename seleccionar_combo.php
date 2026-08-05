<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$categoria_id = (int)($_GET['categoria'] ?? 0);
if(!in_array($categoria_id, [3, 4])) {
    header('Location: menu.php');
    exit;
}

require_once 'config.php';

try {
    $conn = getConnection();

    // Sabores de pizza (también usados para calzone)
    $stmt = $conn->query("SELECT id, nombre FROM productos WHERE categoria_id = 2 AND activo = 1 ORDER BY nombre ASC");
    $sabores = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}

$es_personal = ($categoria_id == 4);
$titulo = $es_personal ? 'Combos Personales' : 'Combos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?> - Pizza Yaja</title>
    <script src="notificacion.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #1a1a1a; padding: 15px; }

        .header {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { color: #ff9800; font-size: 22px; }
        .back-btn {
            background: #666;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
        }

        .combos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            max-width: 900px;
            margin: 0 auto;
        }

        .combo-card {
            background: white;
            border: 3px solid #ff9800;
            border-radius: 10px;
            padding: 14px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        .combo-card:hover { background: #fff3e0; transform: scale(1.02); }

        .combo-nombre { font-size: 17px; font-weight: bold; color: #ff9800; margin-bottom: 5px; }
        .combo-desc { font-size: 12px; color: #666; margin-bottom: 8px; line-height: 1.3; }
        .combo-precio { font-size: 22px; font-weight: bold; color: #333; }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            overflow-y: auto;
        }
        .modal-box {
            max-width: 520px;
            margin: 15px auto;
            background: white;
            border-radius: 10px;
            padding: 15px;
        }
        .modal-titulo { color: #ff9800; font-size: 17px; font-weight: bold; margin-bottom: 3px; }
        .modal-desc { color: #666; font-size: 13px; margin-bottom: 12px; }

        .seccion-label {
            font-weight: bold;
            margin-bottom: 6px;
            display: block;
            font-size: 14px;
        }

        .sabores-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            margin-bottom: 15px;
        }
        .sabor-btn {
            background: #f5f5f5;
            border: 2px solid #ddd;
            padding: 8px 4px;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.2s;
        }
        .sabor-btn:hover { border-color: #ff9800; background: #fff3e0; }
        .sabor-btn.selected { background: #ff9800; color: white; border-color: #ff9800; }

        .bebida-opciones {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }
        .bebida-btn {
            flex: 1;
            padding: 10px 6px;
            border: 2px solid #ddd;
            border-radius: 8px;
            background: #f5f5f5;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            transition: all 0.2s;
        }
        .bebida-btn:hover { border-color: #2196f3; background: #e3f2fd; }
        .bebida-btn.selected { background: #2196f3; color: white; border-color: #2196f3; }

        .precio-display {
            background: #ff9800;
            color: white;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 12px;
        }

        .btn-agregar {
            width: 100%;
            padding: 12px;
            background: #4caf50;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-bottom: 8px;
        }
        .btn-cancelar {
            width: 100%;
            padding: 10px;
            background: #666;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
        }

        .divider { border: none; border-top: 1px solid #eee; margin: 10px 0; }
    </style>
</head>
<body>

<div class="header">
    <h1>🍕 <?php echo $titulo; ?></h1>
    <a href="menu.php" class="back-btn">← Volver</a>
</div>

<div class="combos-grid">

<?php if(!$es_personal): ?>
    <!-- COMBOS REGULARES -->
    <div class="combo-card" onclick="abrirCombo('r1')">
        <div class="combo-nombre">Combo #1</div>
        <div class="combo-desc">Pizza 16 slices + Gaseosa 2.5</div>
        <div class="combo-precio">₡11,000</div>
    </div>
    <div class="combo-card" onclick="abrirCombo('r2')">
        <div class="combo-nombre">Combo #2</div>
        <div class="combo-desc">Pizza 12 slices + Pan de ajo + Gaseosa 1.5</div>
        <div class="combo-precio">₡12,000</div>
    </div>
    <div class="combo-card" onclick="abrirCombo('r3')">
        <div class="combo-nombre">Combo #3</div>
        <div class="combo-desc">2 Pizzas 16 slices + Gaseosa 2.5 + Calzone grande</div>
        <div class="combo-precio">₡20,000</div>
    </div>

<?php else: ?>
    <!-- COMBOS PERSONALES -->
    <div class="combo-card" onclick="abrirCombo('p1')">
        <div class="combo-nombre">Combo Personal #1</div>
        <div class="combo-desc">Pizza personal + Batido o Gaseosa</div>
        <div class="combo-precio">₡3,200</div>
    </div>
    <div class="combo-card" onclick="abrirCombo('p2')">
        <div class="combo-nombre">Combo Personal #2</div>
        <div class="combo-desc">Lasaña + Batido o Gaseosa</div>
        <div class="combo-precio">₡4,200</div>
    </div>
    <div class="combo-card" onclick="abrirCombo('p3')">
        <div class="combo-nombre">Combo Personal #3</div>
        <div class="combo-desc">Calzone Jamón y Queso + Gaseosa o Batido en agua<br><small>(Batido en leche +₡500)</small></div>
        <div class="combo-precio">₡2,000</div>
    </div>
    <div class="combo-card" onclick="abrirCombo('cajita')">
        <div class="combo-nombre">Cajita Infantil</div>
        <div class="combo-desc">Mini pizza + Refresco 255ml + Postre + Juguete sorpresa</div>
        <div class="combo-precio">₡4,200</div>
    </div>
<?php endif; ?>

</div>

<!-- ===== MODALES ===== -->

<!-- Combo Regular #1 y #2 (una pizza, sabor a elegir) -->
<div class="modal-overlay" id="modal_r1">
    <div class="modal-box">
        <div class="modal-titulo">Combo #1</div>
        <div class="modal-desc">Pizza 16 slices + Gaseosa 2.5 — ₡11,000</div>
        <span class="seccion-label">Sabor de la pizza:</span>
        <div class="sabores-grid" id="sabores_r1">
            <?php foreach($sabores as $s): ?>
                <div class="sabor-btn" onclick="seleccionarSabor('r1', <?php echo $s['id']; ?>, '<?php echo addslashes($s['nombre']); ?>', this)">
                    <?php echo htmlspecialchars($s['nombre']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="precio-display">₡11,000</div>
        <button class="btn-agregar" onclick="agregarCombo('r1')">Agregar al Carrito</button>
        <button class="btn-cancelar" onclick="cerrarModal('r1')">Cancelar</button>
    </div>
</div>

<div class="modal-overlay" id="modal_r2">
    <div class="modal-box">
        <div class="modal-titulo">Combo #2</div>
        <div class="modal-desc">Pizza 12 slices + Pan de ajo + Gaseosa 1.5 — ₡12,000</div>
        <span class="seccion-label">Sabor de la pizza:</span>
        <div class="sabores-grid" id="sabores_r2">
            <?php foreach($sabores as $s): ?>
                <div class="sabor-btn" onclick="seleccionarSabor('r2', <?php echo $s['id']; ?>, '<?php echo addslashes($s['nombre']); ?>', this)">
                    <?php echo htmlspecialchars($s['nombre']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="precio-display">₡12,000</div>
        <button class="btn-agregar" onclick="agregarCombo('r2')">Agregar al Carrito</button>
        <button class="btn-cancelar" onclick="cerrarModal('r2')">Cancelar</button>
    </div>
</div>

<!-- Combo Regular #3 (dos pizzas) -->
<div class="modal-overlay" id="modal_r3">
    <div class="modal-box">
        <div class="modal-titulo">Combo #3</div>
        <div class="modal-desc">2 Pizzas 16 slices + Gaseosa 2.5 + Calzone grande — ₡20,000</div>
        <span class="seccion-label">Sabor Pizza 1:</span>
        <div class="sabores-grid" id="sabores_r3_p1">
            <?php foreach($sabores as $s): ?>
                <div class="sabor-btn" onclick="seleccionarSabor('r3_p1', <?php echo $s['id']; ?>, '<?php echo addslashes($s['nombre']); ?>', this)">
                    <?php echo htmlspecialchars($s['nombre']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <hr class="divider">
        <span class="seccion-label">Sabor Pizza 2:</span>
        <div class="sabores-grid" id="sabores_r3_p2">
            <?php foreach($sabores as $s): ?>
                <div class="sabor-btn" onclick="seleccionarSabor('r3_p2', <?php echo $s['id']; ?>, '<?php echo addslashes($s['nombre']); ?>', this)">
                    <?php echo htmlspecialchars($s['nombre']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <hr class="divider">
        <span class="seccion-label">Sabor del Calzone grande:</span>
        <select id="calzone_r3" onchange="seleccionarCalzoneR3()" style="width:100%; padding:10px; border:2px solid #ddd; border-radius:5px; font-size:15px; margin-bottom:12px;">
            <option value="">-- Seleccioná un sabor --</option>
            <?php foreach($sabores as $s): ?>
                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nombre']); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="precio-display">₡20,000</div>
        <button class="btn-agregar" onclick="agregarCombo('r3')">Agregar al Carrito</button>
        <button class="btn-cancelar" onclick="cerrarModal('r3')">Cancelar</button>
    </div>
</div>

<!-- Combo Personal #1 (pizza personal + bebida) -->
<div class="modal-overlay" id="modal_p1">
    <div class="modal-box">
        <div class="modal-titulo">Combo Personal #1</div>
        <div class="modal-desc">Pizza personal + Batido o Gaseosa — ₡3,200</div>
        <span class="seccion-label">Sabor de la pizza personal:</span>
        <div class="sabores-grid" id="sabores_p1">
            <?php foreach($sabores as $s): ?>
                <div class="sabor-btn" onclick="seleccionarSabor('p1', <?php echo $s['id']; ?>, '<?php echo addslashes($s['nombre']); ?>', this)">
                    <?php echo htmlspecialchars($s['nombre']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <hr class="divider">
        <span class="seccion-label">Bebida:</span>
        <div class="bebida-opciones">
            <div class="bebida-btn" onclick="seleccionarBebida('p1', 'Gaseosa', this)">🥤 Gaseosa</div>
            <div class="bebida-btn" onclick="seleccionarBebida('p1', 'Batido', this)">🥛 Batido</div>
        </div>
        <div class="precio-display">₡3,200</div>
        <button class="btn-agregar" onclick="agregarCombo('p1')">Agregar al Carrito</button>
        <button class="btn-cancelar" onclick="cerrarModal('p1')">Cancelar</button>
    </div>
</div>

<!-- Combo Personal #2 (lasaña + bebida) -->
<div class="modal-overlay" id="modal_p2">
    <div class="modal-box">
        <div class="modal-titulo">Combo Personal #2</div>
        <div class="modal-desc">Lasaña + Batido o Gaseosa — ₡4,200</div>
        <span class="seccion-label">Bebida:</span>
        <div class="bebida-opciones">
            <div class="bebida-btn" onclick="seleccionarBebida('p2', 'Gaseosa', this)">🥤 Gaseosa</div>
            <div class="bebida-btn" onclick="seleccionarBebida('p2', 'Batido', this)">🥛 Batido</div>
        </div>
        <div class="precio-display">₡4,200</div>
        <button class="btn-agregar" onclick="agregarCombo('p2')">Agregar al Carrito</button>
        <button class="btn-cancelar" onclick="cerrarModal('p2')">Cancelar</button>
    </div>
</div>

<!-- Combo Personal #3 (calzone + bebida, precio varía) -->
<div class="modal-overlay" id="modal_p3">
    <div class="modal-box">
        <div class="modal-titulo">Combo Personal #3</div>
        <div class="modal-desc">Calzone Jamón y Queso + Gaseosa o Batido</div>
        <span class="seccion-label">Bebida:</span>
        <div class="bebida-opciones">
            <div class="bebida-btn" onclick="seleccionarBebidaP3('Gaseosa', 2000, this)">🥤 Gaseosa<br><small>₡2,000</small></div>
            <div class="bebida-btn" onclick="seleccionarBebidaP3('Batido en agua', 2000, this)">🥤 Batido en agua<br><small>₡2,000</small></div>
            <div class="bebida-btn" onclick="seleccionarBebidaP3('Batido en leche', 2500, this)">🥛 Batido en leche<br><small>₡2,500</small></div>
        </div>
        <div class="precio-display" id="precio_p3">₡2,000</div>
        <button class="btn-agregar" onclick="agregarCombo('p3')">Agregar al Carrito</button>
        <button class="btn-cancelar" onclick="cerrarModal('p3')">Cancelar</button>
    </div>
</div>

<!-- Cajita Infantil (precio fijo) -->
<div class="modal-overlay" id="modal_cajita">
    <div class="modal-box">
        <div class="modal-titulo">Cajita Infantil</div>
        <div class="modal-desc">Mini pizza + Refresco 255ml + Postre + Juguete sorpresa</div>
        <div class="precio-display">₡4,200</div>
        <button class="btn-agregar" onclick="agregarCombo('cajita')">Agregar al Carrito</button>
        <button class="btn-cancelar" onclick="cerrarModal('cajita')">Cancelar</button>
    </div>
</div>

<script>
// Estado de cada combo
const combos = {
    r1:    { precio: 11000, sabor: null, nombre: 'Combo #1' },
    r2:    { precio: 12000, sabor: null, nombre: 'Combo #2' },
    r3:    { precio: 20000, sabor_p1: null, sabor_p2: null, calzone: null, nombre: 'Combo #3' },
    p1:    { precio: 3200,  sabor: null, bebida: null, nombre: 'Combo Personal #1' },
    p2:    { precio: 4200,  bebida: null, nombre: 'Combo Personal #2' },
    p3:    { precio: 2000,  bebida: null, nombre: 'Combo Personal #3' },
    cajita:{ precio: 4200,  nombre: 'Cajita Infantil' }
};

function abrirCombo(tipo) {
    // Resetear estado
    if(combos[tipo].sabor !== undefined) combos[tipo].sabor = null;
    if(combos[tipo].sabor_p1 !== undefined) combos[tipo].sabor_p1 = null;
    if(combos[tipo].sabor_p2 !== undefined) combos[tipo].sabor_p2 = null;
    if(combos[tipo].calzone !== undefined) {
        combos[tipo].calzone = null;
        const sel = document.getElementById('calzone_r3');
        if(sel) sel.value = '';
    }
    if(combos[tipo].bebida !== undefined) combos[tipo].bebida = null;
    if(tipo === 'p3') combos.p3.precio = 2000;

    // Limpiar selecciones visuales
    document.querySelectorAll('#modal_' + tipo + ' .sabor-btn').forEach(b => b.classList.remove('selected'));
    document.querySelectorAll('#modal_' + tipo + ' .bebida-btn').forEach(b => b.classList.remove('selected'));

    document.getElementById('modal_' + tipo).style.display = 'block';
}

function cerrarModal(tipo) {
    document.getElementById('modal_' + tipo).style.display = 'none';
}

function seleccionarSabor(key, id, nombre, el) {
    // key puede ser 'r1', 'r2', 'p1', 'r3_p1', 'r3_p2'
    const contenedor = el.closest('.sabores-grid');
    contenedor.querySelectorAll('.sabor-btn').forEach(b => b.classList.remove('selected'));
    el.classList.add('selected');

    if(key === 'r3_p1') combos.r3.sabor_p1 = nombre;
    else if(key === 'r3_p2') combos.r3.sabor_p2 = nombre;
    else combos[key].sabor = nombre;
}

function seleccionarCalzoneR3() {
    const sel = document.getElementById('calzone_r3');
    combos.r3.calzone = sel.value ? sel.options[sel.selectedIndex].text : null;
}

function seleccionarBebida(tipo, bebida, el) {
    el.closest('.bebida-opciones').querySelectorAll('.bebida-btn').forEach(b => b.classList.remove('selected'));
    el.classList.add('selected');
    combos[tipo].bebida = bebida;
}

function seleccionarBebidaP3(bebida, precio, el) {
    el.closest('.bebida-opciones').querySelectorAll('.bebida-btn').forEach(b => b.classList.remove('selected'));
    el.classList.add('selected');
    combos.p3.bebida = bebida;
    combos.p3.precio = precio;
    document.getElementById('precio_p3').textContent = '₡' + precio.toLocaleString('es-CR');
}

function agregarCombo(tipo) {
    const c = combos[tipo];
    let descripcion = '';

    // Validar y construir descripción
    switch(tipo) {
        case 'r1':
            if(!c.sabor) { mostrarNotificacion('Seleccioná el sabor de la pizza', 'error'); return; }
            descripcion = `Combo #1 — ${c.sabor} + Gaseosa 2.5`;
            break;
        case 'r2':
            if(!c.sabor) { mostrarNotificacion('Seleccioná el sabor de la pizza', 'error'); return; }
            descripcion = `Combo #2 — ${c.sabor} + Pan de ajo + Gaseosa 1.5`;
            break;
        case 'r3':
            if(!c.sabor_p1) { mostrarNotificacion('Seleccioná el sabor de la Pizza 1', 'error'); return; }
            if(!c.sabor_p2) { mostrarNotificacion('Seleccioná el sabor de la Pizza 2', 'error'); return; }
            if(!c.calzone) { mostrarNotificacion('Seleccioná el sabor del Calzone', 'error'); return; }
            descripcion = `Combo #3 — P1: ${c.sabor_p1} / P2: ${c.sabor_p2} / Calzone: ${c.calzone} + Gaseosa 2.5`;
            break;
        case 'p1':
            if(!c.sabor) { mostrarNotificacion('Seleccioná el sabor de la pizza', 'error'); return; }
            if(!c.bebida) { mostrarNotificacion('Seleccioná la bebida', 'error'); return; }
            descripcion = `Combo Personal #1 — ${c.sabor} + ${c.bebida}`;
            break;
        case 'p2':
            if(!c.bebida) { mostrarNotificacion('Seleccioná la bebida', 'error'); return; }
            descripcion = `Combo Personal #2 — Lasaña + ${c.bebida}`;
            break;
        case 'p3':
            if(!c.bebida) { mostrarNotificacion('Seleccioná la bebida', 'error'); return; }
            descripcion = `Combo Personal #3 — Calzone J&Q + ${c.bebida}`;
            break;
        case 'cajita':
            descripcion = 'Cajita Infantil';
            break;
    }

    fetch('agregar_combo.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            nombre: descripcion,
            precio: c.precio
        })
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            mostrarNotificacion(c.nombre + ' agregado');
            cerrarModal(tipo);
            window.location.href = 'menu.php';
        } else {
            mostrarNotificacion('Error: ' + data.error, 'error');
        }
    });
}
</script>

</body>
</html>
