<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$categoria_id = $_GET['categoria'] ?? 0;

require_once 'config.php';

try {
    $conn = getConnection();
    
    // Obtener nombre de categorÃ­a
    $stmt = $conn->prepare("SELECT nombre FROM categorias WHERE id = ?");
    $stmt->execute([$categoria_id]);
    $categoria = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Categorías de pizza (muestran tamaños primero)
    $es_pizza = in_array($categoria_id, [1, 2]);

    // Categorías de bebida (muestran modal con dropdown de sabores)
    $es_bebida = in_array($categoria_id, [7, 8, 9]);

    if($es_pizza) {
        $tabla_tamanos = ($categoria_id == 1) ? 'tamanos_pizza_2x1' : 'tamanos_pizza_individual';
        $stmt = $conn->query("SELECT * FROM $tabla_tamanos ORDER BY orden ASC");
        $tamanos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if($es_bebida) {
        $stmt = $conn->prepare("SELECT * FROM productos WHERE categoria_id = ? AND activo = 1 ORDER BY nombre ASC");
        $stmt->execute([$categoria_id]);
        $sabores_bebida = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title><?php echo $categoria['nombre']; ?> - Pizza Yaja</title>
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
            padding: 15px;
        }
        .header {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            color: #ff9800;
            font-size: 22px;
        }
        .back-btn {
            background: #666;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
        }
        .tamanos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 8px;
            max-width: 900px;
            margin: 0 auto;
        }
        .tamano-card {
            background: white;
            border: 3px solid #ff9800;
            border-radius: 10px;
            padding: 14px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .tamano-card:hover {
            background: #ff9800;
            color: white;
            transform: scale(1.05);
        }
        .tamano-nombre {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 6px;
        }
        .tamano-slices {
            font-size: 13px;
            color: #666;
            margin-bottom: 6px;
        }
        .tamano-card:hover .tamano-slices {
            color: white;
        }
        .tamano-precio {
            font-size: 20px;
            font-weight: bold;
            color: #ff9800;
        }
        .tamano-card:hover .tamano-precio {
            color: white;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?php echo htmlspecialchars($categoria['nombre']); ?></h1>
        <a href="menu.php" class="back-btn">← Volver</a>
    </div>
    
    <?php if($es_pizza): ?>
        <!-- Tamaños de pizza -->
        <div class="tamanos-grid">
            <?php foreach($tamanos as $tam): ?>
                <div class="tamano-card" onclick="seleccionarTamano(<?php echo $tam['id']; ?>, '<?php echo $tam['nombre']; ?>', <?php echo $tam['precio']; ?>, <?php echo $categoria_id; ?>)">
                    <div class="tamano-nombre"><?php echo $tam['nombre']; ?></div>
                    <div class="tamano-slices"><?php echo $tam['slices']; ?> slices</div>
                    <div class="tamano-precio">₡<?php echo number_format($tam['precio'], 0); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif($es_bebida): ?>
        <!-- Bebidas: un solo botón que abre modal con dropdown de sabores -->
        <div style="max-width: 400px; margin: 0 auto;">
            <?php if(empty($sabores_bebida)): ?>
                <div style="color: white; text-align: center; padding: 40px;">No hay sabores disponibles</div>
            <?php else: ?>
                <div class="tamano-card" onclick="abrirModalBebida()" style="padding: 30px; text-align: center;">
                    <div class="tamano-nombre">🥤 Seleccionar Sabor</div>
                    <div style="font-size: 14px; color: #666; margin-top: 8px;">
                        <?php echo count($sabores_bebida); ?> opciones disponibles
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- Productos simples -->
        <div class="productos-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; max-width: 900px; margin: 0 auto;">
            <?php
            $stmt = $conn->prepare("SELECT * FROM productos WHERE categoria_id = ? AND activo = 1 ORDER BY precio ASC, nombre ASC");
            $stmt->execute([$categoria_id]);
            $productos_simples = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if(empty($productos_simples)):
            ?>
                <div style="color: white; text-align: center; padding: 40px; grid-column: 1/-1;">
                    No hay productos en esta categoría
                </div>
            <?php else: ?>
                <?php foreach($productos_simples as $prod): ?>
                    <div class="producto-card" style="background: #fff; border-radius: 10px; padding: 15px; cursor: pointer; transition: transform 0.2s;" onclick="abrirModalProductoSimple(<?php echo $prod['id']; ?>, '<?php echo addslashes($prod['nombre']); ?>', <?php echo $prod['precio']; ?>, <?php echo $prod['es_premium']; ?>, <?php echo $prod['precio_premium']; ?>)">
                        <div class="producto-nombre" style="font-weight: bold; margin-bottom: 8px;">
                            <?php echo htmlspecialchars($prod['nombre']); ?>
                        </div>
                        <div class="producto-precio" style="color: #ff9800; font-size: 20px; font-weight: bold;">
                            ₡<?php echo number_format($prod['precio'], 0); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <script>
        function seleccionarTamano(tamanoId, nombreTamano, precio, categoriaId) {
            // Redirigir a pÃ¡gina de selecciÃ³n de sabores
            window.location.href = 'seleccionar_sabor.php?categoria=' + categoriaId + '&tamano=' + tamanoId + '&nombre=' + encodeURIComponent(nombreTamano) + '&precio=' + precio;
        }
    </script>

    <!-- Modal para productos simples -->
    <div id="modal_producto_simple" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; overflow-y: auto;">
        <div style="max-width: 500px; margin: 20px auto; background: white; border-radius: 10px; padding: 15px;">
            <h2 id="modal_titulo" style="color: #ff9800; margin-bottom: 12px; font-size: 18px;"></h2>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 6px; font-weight: bold; font-size: 14px;">Cantidad:</label>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button onclick="cambiarCantidadModal(-1)" style="width:44px; height:44px; font-size:22px; border:2px solid #ddd; border-radius:5px; background:#f5f5f5; cursor:pointer;">−</button>
                    <input type="number" id="modal_cantidad" value="1" min="1" style="flex:1; padding:10px; border:2px solid #ddd; border-radius:5px; font-size:16px; text-align:center;">
                    <button onclick="cambiarCantidadModal(1)" style="width:44px; height:44px; font-size:22px; border:2px solid #ddd; border-radius:5px; background:#f5f5f5; cursor:pointer;">+</button>
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 6px; font-weight: bold; font-size: 14px;">Comentarios:</label>
                <textarea id="modal_comentarios" rows="2" placeholder="Notas especiales..." style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 5px; font-family: Arial; font-size: 14px;"></textarea>
            </div>

            <div id="modal_precio_total" style="background: #ff9800; color: white; padding: 10px; border-radius: 5px; text-align: center; font-size: 20px; font-weight: bold; margin-bottom: 12px;">
                Total: ₡0
            </div>

            <button onclick="agregarProductoSimple()" style="width: 100%; padding: 12px; background: #4caf50; color: white; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; margin-bottom: 8px;">
                Agregar al Carrito
            </button>

            <button onclick="cerrarModalProductoSimple()" style="width: 100%; padding: 10px; background: #666; color: white; border: none; border-radius: 5px; font-size: 14px; cursor: pointer;">
                Cancelar
            </button>
        </div>
    </div>

    <script>
    let productoSimpleActual = null;

    function abrirModalProductoSimple(id, nombre, precio, esPremium, precioPremium) {
        // Si es calzone con sabor (es_premium = 1 en calzones significa "necesita sabor")
        if(<?php echo $categoria_id; ?> == 5 && esPremium == 1) {
            // Redirigir a selecciÃ³n de sabor para calzone
            window.location.href = 'seleccionar_sabor_calzone.php?producto_id=' + id + '&nombre=' + encodeURIComponent(nombre) + '&precio=' + precio;
            return;
        }
        
        // Producto simple normal
        // Si es calzone, agregar "Calzone" al nombre
        if(<?php echo $categoria_id; ?> == 5 && nombre.indexOf('Calzone') === -1) {
            nombre = 'Calzone ' + nombre;
        }
        productoSimpleActual = {
            id: id,
            nombre: nombre,
            precio: precio,
            esPremium: esPremium,
            precioPremium: precioPremium
        };
        
        document.getElementById('modal_titulo').textContent = (<?php echo $categoria_id; ?> == 5 ? 'Calzone ' : '') + nombre;
        document.getElementById('modal_cantidad').value = 1;
        document.getElementById('modal_comentarios').value = '';
        document.getElementById('modal_producto_simple').style.display = 'block';
        
        actualizarPrecioModal();
    }

    function cerrarModalProductoSimple() {
        document.getElementById('modal_producto_simple').style.display = 'none';
        productoSimpleActual = null;
    }

    function cambiarCantidadModal(delta) {
        const input = document.getElementById('modal_cantidad');
        input.value = Math.max(1, (parseInt(input.value) || 1) + delta);
        actualizarPrecioModal();
    }

    function actualizarPrecioModal() {
        if(!productoSimpleActual) return;

        let cantidad = parseInt(document.getElementById('modal_cantidad').value) || 1;
        let precioUnitario = productoSimpleActual.precio;
        let total = precioUnitario * cantidad;

        document.getElementById('modal_precio_total').textContent = 'Total: ₡' + total.toLocaleString('es-CR');
    }

    function agregarProductoSimple() {
        if (!productoSimpleActual) {
            mostrarNotificacion('No se seleccionó ningún producto', 'error');
            return;
        }

        let cantidad = parseInt(document.getElementById('modal_cantidad').value) || 1;
        let comentarios = document.getElementById('modal_comentarios').value;

        let datos = {
            tipo: 'producto_simple',
            producto_id: productoSimpleActual.id,
            producto_nombre: productoSimpleActual.nombre,
            precio: productoSimpleActual.precio,
            cantidad: cantidad,
            comentarios: comentarios
        };

        fetch('agregar_producto_simple.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(datos)
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                mostrarNotificacion('Producto agregado');
                cerrarModalProductoSimple();
                window.location.href = 'menu.php';
            } else {
                mostrarNotificacion('Error: ' + data.error, 'error');
            }
        })
        .catch(error => {
            mostrarNotificacion('Error al agregar producto', 'error');
            console.error(error);
        });
    }
    </script>

    <!-- Modal para bebidas (batidos y refrescos) -->
    <div id="modal_bebida" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; overflow-y: auto;">
        <div style="max-width: 500px; margin: 20px auto; background: white; border-radius: 10px; padding: 15px;">
            <h2 style="color: #ff9800; margin-bottom: 12px; font-size: 18px;">🥤 <?php echo htmlspecialchars($categoria['nombre'] ?? ''); ?></h2>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 6px; font-weight: bold; font-size: 14px;">Sabor:</label>
                <select id="bebida_sabor" onchange="actualizarPrecioBebida()" style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 5px; font-size: 15px;">
                    <option value="">-- Seleccioná un sabor --</option>
                    <?php foreach($sabores_bebida ?? [] as $s): ?>
                        <option value="<?php echo $s['id']; ?>" data-precio="<?php echo $s['precio']; ?>">
                            <?php echo htmlspecialchars($s['nombre']); ?> — ₡<?php echo number_format($s['precio'], 0); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 6px; font-weight: bold; font-size: 14px;">Cantidad:</label>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button onclick="cambiarCantidadBebidaProd(-1)" style="width:44px; height:44px; font-size:22px; border:2px solid #ddd; border-radius:5px; background:#f5f5f5; cursor:pointer;">−</button>
                    <input type="number" id="bebida_cantidad" value="1" min="1" onchange="actualizarPrecioBebida()" style="flex:1; padding:10px; border:2px solid #ddd; border-radius:5px; font-size:16px; text-align:center;">
                    <button onclick="cambiarCantidadBebidaProd(1)" style="width:44px; height:44px; font-size:22px; border:2px solid #ddd; border-radius:5px; background:#f5f5f5; cursor:pointer;">+</button>
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 6px; font-weight: bold; font-size: 14px;">Comentarios:</label>
                <textarea id="bebida_comentarios" rows="2" placeholder="Notas especiales..." style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 5px; font-family: Arial; font-size: 14px;"></textarea>
            </div>

            <div id="bebida_precio_total" style="background: #ff9800; color: white; padding: 10px; border-radius: 5px; text-align: center; font-size: 20px; font-weight: bold; margin-bottom: 12px;">
                Total: ₡0
            </div>

            <button onclick="agregarBebida()" style="width: 100%; padding: 12px; background: #4caf50; color: white; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; margin-bottom: 8px;">
                Agregar al Carrito
            </button>
            <button onclick="cerrarModalBebida()" style="width: 100%; padding: 10px; background: #666; color: white; border: none; border-radius: 5px; font-size: 14px; cursor: pointer;">
                Cancelar
            </button>
        </div>
    </div>

    <script>
    function abrirModalBebida() {
        document.getElementById('bebida_sabor').value = '';
        document.getElementById('bebida_cantidad').value = 1;
        document.getElementById('bebida_comentarios').value = '';
        document.getElementById('bebida_precio_total').textContent = 'Total: ₡0';
        document.getElementById('modal_bebida').style.display = 'block';
    }

    function cerrarModalBebida() {
        document.getElementById('modal_bebida').style.display = 'none';
    }

    function cambiarCantidadBebidaProd(delta) {
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

        const catId = <?php echo $categoria_id; ?>;
        const prefijo = catId == 7 ? 'Batido de ' : catId == 8 ? 'Batido (Leche) de ' : catId == 9 ? 'Refresco de ' : '';
        const sabor = selected.text.split(' — ')[0];

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
                window.location.href = 'menu.php';
            } else {
                mostrarNotificacion('Error: ' + data.error, 'error');
            }
        });
    }
    </script>
</body>
</html>


