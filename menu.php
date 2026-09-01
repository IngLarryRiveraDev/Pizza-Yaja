<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

// Verificar que estÃ© logueado y sea camarero
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'camarero') {
    header('Location: index.php');
    exit;
}

// ConexiÃ³n a la base de datos
require_once 'config.php';

try {
    $conn = getConnection();

    $stmt = $conn->query("SELECT * FROM categorias ORDER BY orden ASC");
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cargar sabores de bebidas agrupados por categoría para los modales
    $stmt = $conn->query("SELECT id, categoria_id, nombre, precio FROM productos WHERE categoria_id IN (7, 8, 9) AND activo = 1 ORDER BY nombre ASC");
    $bebidas_all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $bebidas_por_cat = [];
    foreach($bebidas_all as $b) {
        $bebidas_por_cat[$b['categoria_id']][] = $b;
    }

} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menú - Pizza Yaja</title>
    <script src="notificacion.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            background: #1a1a1a;
            padding: 20px;
        }
        .header {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            color: #ff9800;
            font-size: 24px;
        }
        .user-info {
            color: #666;
        }
        .logout-btn {
            background: #c62828;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .categorias-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 8px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .categoria-btn {
            background: white;
            border: 3px solid #ff9800;
            padding: 16px 10px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            color: #333;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: block;
            text-align: center;
        }
        .categoria-btn:hover {
            background: #ff9800;
            color: white;
            transform: scale(1.05);
        }
        .top-buttons {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }
        .top-btn {
            background: #4caf50;
            color: white;
            padding: 10px 14px;
            border: none;
            border-radius: 5px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .arqueo-btn {
            background: #2196f3;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>🍕 Pizza Yaja</h1>
            <span class="user-info">Camarero: <?php echo htmlspecialchars($_SESSION['nombre']); ?></span>
        </div>
        <a href="logout.php" class="logout-btn">Cerrar Sesión</a>
    </div>
    
    <div class="top-buttons">
        <a href="ordenes_activas.php" class="top-btn" style="background: #2196f3;">← Órdenes Activas</a>
        <a href="ver_orden.php" class="top-btn">Ver Orden Actual</a>
    </div>
    
    <div class="categorias-grid">
        <?php if(empty($categorias)): ?>
            <p style="color: white; grid-column: 1/-1; text-align: center; padding: 40px;">
                No hay categorías. Necesitas agregar categorías desde el panel de administración.
            </p>
        <?php else: ?>
            <?php foreach($categorias as $cat): ?>
                <?php if(in_array($cat['id'], [7, 8, 9])): ?>
                    <a href="#" class="categoria-btn" onclick="abrirModalBebida(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['nombre']); ?>'); return false;">
                        <?php echo htmlspecialchars($cat['nombre']); ?>
                    </a>
                <?php elseif(in_array($cat['id'], [3, 4, 11])): ?>
                    <a href="seleccionar_combo.php?categoria=<?php echo $cat['id']; ?>" class="categoria-btn">
                        <?php echo htmlspecialchars($cat['nombre']); ?>
                    </a>
                <?php else: ?>
                    <a href="productos.php?categoria=<?php echo $cat['id']; ?>" class="categoria-btn">
                        <?php echo htmlspecialchars($cat['nombre']); ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <!-- Modal de bebidas -->
    <div id="modal_bebida" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:1000; overflow-y:auto;">
        <div style="max-width:500px; margin:20px auto; background:white; border-radius:10px; padding:15px;">
            <h2 id="bebida_titulo" style="color:#ff9800; margin-bottom:12px; font-size:18px;"></h2>

            <div style="margin-bottom:12px;">
                <label style="display:block; margin-bottom:6px; font-weight:bold; font-size:14px;">Sabor:</label>
                <select id="bebida_sabor" onchange="actualizarPrecioBebida()" style="width:100%; padding:10px; border:2px solid #ddd; border-radius:5px; font-size:15px;">
                    <option value="">-- Seleccioná un sabor --</option>
                </select>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; margin-bottom:6px; font-weight:bold; font-size:14px;">Cantidad:</label>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button onclick="cambiarCantidadBebida(-1)" style="width:44px; height:44px; font-size:22px; border:2px solid #ddd; border-radius:5px; background:#f5f5f5; cursor:pointer;">−</button>
                    <input type="number" id="bebida_cantidad" value="1" min="1" onchange="actualizarPrecioBebida()" style="flex:1; padding:10px; border:2px solid #ddd; border-radius:5px; font-size:16px; text-align:center;">
                    <button onclick="cambiarCantidadBebida(1)" style="width:44px; height:44px; font-size:22px; border:2px solid #ddd; border-radius:5px; background:#f5f5f5; cursor:pointer;">+</button>
                </div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; margin-bottom:6px; font-weight:bold; font-size:14px;">Comentarios:</label>
                <textarea id="bebida_comentarios" rows="2" placeholder="Notas especiales..." style="width:100%; padding:8px; border:2px solid #ddd; border-radius:5px; font-family:Arial; font-size:14px;"></textarea>
            </div>

            <div id="bebida_precio_total" style="background:#ff9800; color:white; padding:10px; border-radius:5px; text-align:center; font-size:20px; font-weight:bold; margin-bottom:12px;">
                Total: ₡0
            </div>

            <button onclick="agregarBebida()" style="width:100%; padding:12px; background:#4caf50; color:white; border:none; border-radius:5px; font-size:16px; font-weight:bold; cursor:pointer; margin-bottom:8px;">
                Agregar al Carrito
            </button>
            <button onclick="cerrarModalBebida()" style="width:100%; padding:10px; background:#666; color:white; border:none; border-radius:5px; font-size:14px; cursor:pointer;">
                Cancelar
            </button>
        </div>
    </div>

    <script>
    const bebidasData = <?php echo json_encode($bebidas_por_cat); ?>;
    let catBebidaActual = null;

    function abrirModalBebida(catId, catNombre) {
        catBebidaActual = catId;
        const sabores = bebidasData[catId] || [];

        document.getElementById('bebida_titulo').textContent = '🥤 ' + catNombre;

        const select = document.getElementById('bebida_sabor');
        select.innerHTML = '<option value="">-- Seleccioná un sabor --</option>';
        sabores.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.dataset.precio = s.precio;
            opt.textContent = s.nombre + ' — ₡' + parseInt(s.precio).toLocaleString('es-CR');
            select.appendChild(opt);
        });

        document.getElementById('bebida_cantidad').value = 1;
        document.getElementById('bebida_comentarios').value = '';
        document.getElementById('bebida_precio_total').textContent = 'Total: ₡0';
        document.getElementById('modal_bebida').style.display = 'block';
    }

    function cerrarModalBebida() {
        document.getElementById('modal_bebida').style.display = 'none';
        catBebidaActual = null;
    }

    function cambiarCantidadBebida(delta) {
        const input = document.getElementById('bebida_cantidad');
        input.value = Math.max(1, (parseInt(input.value) || 1) + delta);
        actualizarPrecioBebida();
    }

    function actualizarPrecioBebida() {
        const select = document.getElementById('bebida_sabor');
        const cantidad = parseInt(document.getElementById('bebida_cantidad').value) || 1;
        const selected = select.options[select.selectedIndex];
        const precio = selected ? parseFloat(selected.dataset.precio) || 0 : 0;
        document.getElementById('bebida_precio_total').textContent = 'Total: ₡' + (precio * cantidad).toLocaleString('es-CR');
    }

    function agregarBebida() {
        const select = document.getElementById('bebida_sabor');
        if(!select.value) {
            mostrarNotificacion('Seleccioná un sabor', 'error');
            return;
        }
        const selected = select.options[select.selectedIndex];
        const cantidad = parseInt(document.getElementById('bebida_cantidad').value) || 1;
        const comentarios = document.getElementById('bebida_comentarios').value;

        const prefijo = catBebidaActual == 7 ? 'Batido de ' : catBebidaActual == 8 ? 'Batido (Leche) de ' : 'Refresco de ';
        const sabor = selected.textContent.split(' — ')[0];

        fetch('agregar_producto_simple.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                tipo: 'producto_simple',
                producto_id: parseInt(select.value),
                producto_nombre: prefijo + sabor,
                precio: parseFloat(selected.dataset.precio),
                cantidad: cantidad,
                comentarios: comentarios
            })
        })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                mostrarNotificacion('Bebida agregada');
                cerrarModalBebida();
            } else {
                mostrarNotificacion('Error: ' + data.error, 'error');
            }
        });
    }
    </script>
</body>
</html>


