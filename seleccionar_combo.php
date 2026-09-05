<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$categoria_id = (int)($_GET['categoria'] ?? 0);
if(!in_array($categoria_id, [3, 4, 11])) {
    header('Location: menu.php');
    exit;
}

require_once 'config.php';

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

try {
    $conn = getConnection();
    setupCombosTable($conn);

    $stmt = $conn->prepare("SELECT * FROM combos WHERE categoria_id = ? AND activo = 1 ORDER BY orden ASC, id ASC");
    $stmt->execute([$categoria_id]);
    $combos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->query("SELECT id, nombre FROM productos WHERE categoria_id = 2 AND activo = 1 ORDER BY nombre ASC");
    $sabores = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}

$titulo = match($categoria_id) {
    4  => 'Combos Personales',
    11 => 'Promociones',
    default => 'Combos',
};
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
            background: white; padding: 15px; border-radius: 10px;
            margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;
        }
        .header h1 { color: #ff9800; font-size: 22px; }
        .back-btn { background: #666; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; font-weight: bold; }

        .combos-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px; max-width: 900px; margin: 0 auto;
        }
        .combo-card {
            background: white; border: 3px solid #ff9800; border-radius: 10px;
            padding: 14px; cursor: pointer; transition: all 0.3s; text-align: center;
        }
        .combo-card:hover { background: #fff3e0; transform: scale(1.02); }
        .combo-nombre { font-size: 17px; font-weight: bold; color: #ff9800; margin-bottom: 5px; }
        .combo-desc { font-size: 12px; color: #666; margin-bottom: 8px; line-height: 1.3; }
        .combo-precio { font-size: 22px; font-weight: bold; color: #333; }

        .sin-combos { color: #aaa; text-align: center; padding: 40px; background: #222; border-radius: 10px; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.8);
            z-index: 1000; overflow-y: auto;
        }
        .modal-box { max-width: 520px; margin: 15px auto; background: white; border-radius: 10px; padding: 15px; }
        .modal-titulo { color: #ff9800; font-size: 17px; font-weight: bold; margin-bottom: 3px; }
        .modal-desc { color: #666; font-size: 13px; margin-bottom: 12px; }
        .seccion-label { font-weight: bold; margin-bottom: 6px; display: block; font-size: 14px; }

        .sabores-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 15px; }
        .sabor-btn {
            background: #f5f5f5; border: 2px solid #ddd; padding: 8px 4px; border-radius: 8px;
            cursor: pointer; text-align: center; font-size: 12px; font-weight: bold; transition: all 0.2s;
        }
        .sabor-btn:hover { border-color: #ff9800; background: #fff3e0; }
        .sabor-btn.selected { background: #ff9800; color: white; border-color: #ff9800; }

        .bebida-opciones { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
        .bebida-btn {
            flex: 1; min-width: 80px; padding: 10px 6px; border: 2px solid #ddd; border-radius: 8px;
            background: #f5f5f5; cursor: pointer; font-size: 13px; font-weight: bold; text-align: center; transition: all 0.2s;
        }
        .bebida-btn:hover { border-color: #2196f3; background: #e3f2fd; }
        .bebida-btn.selected { background: #2196f3; color: white; border-color: #2196f3; }

        .precio-display { background: #ff9800; color: white; padding: 10px; border-radius: 8px; text-align: center; font-size: 20px; font-weight: bold; margin-bottom: 12px; }
        .btn-agregar { width: 100%; padding: 12px; background: #4caf50; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; margin-bottom: 8px; }
        .btn-cancelar { width: 100%; padding: 10px; background: #666; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; }
        .divider { border: none; border-top: 1px solid #eee; margin: 10px 0; }
    </style>
</head>
<body>

<div class="header">
    <h1>🍕 <?php echo $titulo; ?></h1>
    <a href="menu.php" class="back-btn">← Volver</a>
</div>

<?php if(empty($combos)): ?>
    <div class="sin-combos">No hay combos disponibles en este momento.</div>
<?php else: ?>
<div class="combos-grid">
    <?php foreach($combos as $c): ?>
        <div class="combo-card" onclick="abrirCombo(<?php echo $c['id']; ?>)">
            <div class="combo-nombre"><?php echo htmlspecialchars($c['nombre']); ?></div>
            <div class="combo-desc"><?php echo htmlspecialchars($c['descripcion']); ?></div>
            <div class="combo-precio">₡<?php echo number_format($c['precio'], 0); ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal dinámico único -->
<div class="modal-overlay" id="modal_combo">
    <div class="modal-box">
        <div class="modal-titulo" id="mc_titulo"></div>
        <div class="modal-desc" id="mc_desc"></div>

        <!-- Sección pizza 1 -->
        <div id="sec_pizza1" style="display:none">
            <span class="seccion-label" id="lbl_pizza1">Sabor de la pizza:</span>
            <div class="sabores-grid" id="grid_pizza1">
                <?php foreach($sabores as $s): ?>
                    <div class="sabor-btn" onclick="selSabor('pizza1','<?php echo addslashes($s['nombre']); ?>',this)">
                        <?php echo htmlspecialchars($s['nombre']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <hr class="divider">
        </div>

        <!-- Sección pizza 2 -->
        <div id="sec_pizza2" style="display:none">
            <span class="seccion-label">Sabor de la segunda pizza:</span>
            <div class="sabores-grid" id="grid_pizza2">
                <?php foreach($sabores as $s): ?>
                    <div class="sabor-btn" onclick="selSabor('pizza2','<?php echo addslashes($s['nombre']); ?>',this)">
                        <?php echo htmlspecialchars($s['nombre']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <hr class="divider">
        </div>

        <!-- Sección calzone -->
        <div id="sec_calzone" style="display:none">
            <span class="seccion-label">Sabor del calzone grande:</span>
            <select id="sel_calzone" onchange="state.calzone = this.value ? this.options[this.selectedIndex].text : null"
                style="width:100%; padding:10px; border:2px solid #ddd; border-radius:5px; font-size:15px; margin-bottom:12px;">
                <option value="">-- Seleccioná un sabor --</option>
                <?php foreach($sabores as $s): ?>
                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nombre']); ?></option>
                <?php endforeach; ?>
            </select>
            <hr class="divider">
        </div>

        <!-- Sección bebida -->
        <div id="sec_bebida" style="display:none">
            <span class="seccion-label">Bebida:</span>
            <div class="bebida-opciones" id="bebida_opciones"></div>
            <hr class="divider">
        </div>

        <div class="precio-display" id="mc_precio">₡0</div>
        <button class="btn-agregar" onclick="agregarCombo()">Agregar al Carrito</button>
        <button class="btn-cancelar" onclick="cerrarModal()">Cancelar</button>
    </div>
</div>

<script>
const combosData = <?php echo json_encode(array_values($combos)); ?>;

let comboActual = null;
let state = { pizza1: null, pizza2: null, calzone: null, bebida: null, precio: 0 };

function abrirCombo(id) {
    comboActual = combosData.find(c => c.id == id);
    if(!comboActual) return;

    // Reset state
    state = { pizza1: null, pizza2: null, calzone: null, bebida: null, precio: comboActual.precio };

    // Encabezado
    document.getElementById('mc_titulo').textContent = comboActual.nombre;
    document.getElementById('mc_desc').textContent   = comboActual.descripcion + ' — ₡' + parseInt(comboActual.precio).toLocaleString('es-CR');
    document.getElementById('mc_precio').textContent = '₡' + parseInt(comboActual.precio).toLocaleString('es-CR');

    // Limpiar selecciones
    document.querySelectorAll('.sabor-btn').forEach(b => b.classList.remove('selected'));
    document.querySelectorAll('.bebida-btn').forEach(b => b.classList.remove('selected'));
    const selCalzone = document.getElementById('sel_calzone');
    selCalzone.value = '';
    state.calzone = null;

    // Mostrar/ocultar secciones
    document.getElementById('sec_pizza1').style.display  = +comboActual.pide_pizza         ? 'block' : 'none';
    document.getElementById('sec_pizza2').style.display  = +comboActual.pide_segunda_pizza  ? 'block' : 'none';
    document.getElementById('sec_calzone').style.display = +comboActual.pide_calzone        ? 'block' : 'none';
    document.getElementById('sec_bebida').style.display  = +comboActual.pide_bebida         ? 'block' : 'none';

    // Etiqueta pizza1
    document.getElementById('lbl_pizza1').textContent =
        +comboActual.pide_segunda_pizza ? 'Sabor de la primera pizza:' : 'Sabor de la pizza:';

    // Opciones de bebida
    if(+comboActual.pide_bebida) {
        const cont = document.getElementById('bebida_opciones');
        cont.innerHTML = '';
        const opciones = [
            { label: '🥤 Gaseosa', bebida: 'Gaseosa', precio: comboActual.precio },
            { label: '🥤 Batido en agua', bebida: 'Batido en agua', precio: comboActual.precio },
        ];
        if(comboActual.precio_con_leche) {
            opciones.push({ label: '🥛 Batido en leche', bebida: 'Batido en leche', precio: comboActual.precio_con_leche });
        } else {
            opciones.push({ label: '🥛 Batido en leche', bebida: 'Batido en leche', precio: comboActual.precio });
        }
        opciones.forEach(op => {
            const btn = document.createElement('div');
            btn.className = 'bebida-btn';
            btn.innerHTML = op.label + (comboActual.precio_con_leche && op.bebida === 'Batido en leche'
                ? '<br><small>₡' + parseInt(op.precio).toLocaleString('es-CR') + '</small>' : '');
            btn.onclick = function() {
                document.querySelectorAll('#bebida_opciones .bebida-btn').forEach(b => b.classList.remove('selected'));
                this.classList.add('selected');
                state.bebida = op.bebida;
                state.precio = op.precio;
                document.getElementById('mc_precio').textContent = '₡' + parseInt(op.precio).toLocaleString('es-CR');
            };
            cont.appendChild(btn);
        });
    }

    document.getElementById('modal_combo').style.display = 'block';
}

function cerrarModal() {
    document.getElementById('modal_combo').style.display = 'none';
    comboActual = null;
}

function selSabor(key, nombre, el) {
    el.closest('.sabores-grid').querySelectorAll('.sabor-btn').forEach(b => b.classList.remove('selected'));
    el.classList.add('selected');
    state[key] = nombre;
}

function agregarCombo() {
    if(!comboActual) return;
    const c = comboActual;

    if(+c.pide_pizza && !state.pizza1) {
        mostrarNotificacion('Seleccioná el sabor de la pizza', 'error'); return;
    }
    if(+c.pide_segunda_pizza && !state.pizza2) {
        mostrarNotificacion('Seleccioná el sabor de la segunda pizza', 'error'); return;
    }
    if(+c.pide_calzone && !state.calzone) {
        mostrarNotificacion('Seleccioná el sabor del calzone', 'error'); return;
    }
    if(+c.pide_bebida && !state.bebida) {
        mostrarNotificacion('Seleccioná la bebida', 'error'); return;
    }

    // Construir descripción
    const qp = s => s ? s.replace(/^Pizza\s+/i, '') : s;
    let partes = [c.nombre];
    if(state.pizza1 && !state.pizza2) partes.push(qp(state.pizza1));
    if(state.pizza1 && state.pizza2)  partes.push('P1: ' + qp(state.pizza1), 'P2: ' + qp(state.pizza2));
    if(state.calzone)                 partes.push('Calzone: ' + qp(state.calzone));
    if(state.bebida)                  partes.push(state.bebida);
    const descripcion = partes.join(' — ');

    fetch('agregar_combo.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ nombre: descripcion, precio: state.precio })
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            mostrarNotificacion(c.nombre + ' agregado');
            cerrarModal();
            window.location.href = 'menu.php';
        } else {
            mostrarNotificacion('Error: ' + data.error, 'error');
        }
    });
}
</script>
</body>
</html>
