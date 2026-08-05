<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// Recibir datos del calzone
$producto_id = $_GET['producto_id'] ?? 0;
$nombre = $_GET['nombre'] ?? '';
$precio = $_GET['precio'] ?? 0;

// ConexiÃ³n BD
require_once 'config.php';

try {
    $conn = getConnection();
    
    // Obtener sabores de pizza (categorÃ­a 2)
    $stmt = $conn->query("SELECT * FROM productos WHERE categoria_id = 2 AND activo = 1 ORDER BY nombre");
    $sabores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seleccionar Sabor - Calzone</title>
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
        }
        .header h1 {
            color: #ff9800;
            font-size: 20px;
            margin-bottom: 5px;
        }
        .back-btn {
            background: #666;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
            margin-top: 10px;
        }
        .seccion {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        .seccion-titulo {
            font-size: 18px;
            font-weight: bold;
            color: #ff9800;
            margin-bottom: 15px;
        }
        .premium-badge {
            background: #c62828;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        input[type="number"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 18px;
        }
        textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
            resize: vertical;
            font-family: Arial;
        }
        .precio-total {
            background: #ff9800;
            color: white;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .btn-agregar {
            background: #4caf50;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Calzone - <?php echo htmlspecialchars($nombre); ?></h1>
        <div style="color: #666;">Precio base: ₡<?php echo number_format($precio, 0); ?></div>
        <a href="productos.php?categoria=5" class="back-btn">← Volver</a>
    </div>

    <div class="seccion">
        <div class="form-group">
            <label>Seleccionar Sabor:</label>
            <select id="sabor_select" onchange="seleccionarSabor()" style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 5px; font-size: 16px;">
                <option value="">-- Seleccione un sabor --</option>
                <?php foreach($sabores as $sabor): ?>
                    <option value='<?php echo json_encode(['id' => $sabor['id'], 'nombre' => $sabor['nombre'], 'esPremium' => $sabor['es_premium'], 'precioPremium' => $sabor['precio_premium']]); ?>'>
                        <?php echo htmlspecialchars($sabor['nombre']); ?>
                        <?php if($sabor['es_premium']): ?>
                            +₡<?php echo number_format($sabor['precio_premium']); ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="seccion">
        <div class="form-group">
            <label>Cantidad:</label>
            <input type="number" id="cantidad" value="1" min="1" onchange="calcularTotal()">
        </div>

        <div class="form-group">
            <label>Comentarios especiales:</label>
            <textarea id="comentarios" rows="3" placeholder="Ej: Sin cebolla, bien cocido, etc."></textarea>
        </div>
    </div>

    <div class="precio-total" id="precio_total">
        Total: ₡<?php echo number_format($precio, 0); ?>
    </div>

    <button class="btn-agregar" onclick="agregarCalzone()">Agregar al Carrito</button>

    <script>
        let precioBase = <?php echo $precio; ?>;
        let productoId = <?php echo $producto_id; ?>;
        let productoNombre = '<?php echo addslashes($nombre); ?>';
        let saborSeleccionado = null;

        function seleccionarSabor() {
            let select = document.getElementById('sabor_select');
            let selectedValue = select.value;

            if (selectedValue) {
                saborSeleccionado = JSON.parse(selectedValue);
                calcularTotal();
            } else {
                saborSeleccionado = null;
                calcularTotal();
            }
        }

        function calcularTotal() {
            let cantidad = parseInt(document.getElementById('cantidad').value) || 1;
            let precioUnitario = precioBase;
            
            // Agregar costo premium si aplica
            if(saborSeleccionado && saborSeleccionado.esPremium) {
                precioUnitario += parseFloat(saborSeleccionado.precioPremium);
            }
            
            let total = precioUnitario * cantidad;
            document.getElementById('precio_total').textContent = 'Total: ₡' + total.toLocaleString('es-CR');
        }

        function agregarCalzone() {
            if(!saborSeleccionado) {
                mostrarNotificacion('Debes seleccionar un sabor', 'error');
                return;
            }

            let cantidad = parseInt(document.getElementById('cantidad').value) || 1;
            let comentarios = document.getElementById('comentarios').value;
            
            // Calcular precio unitario con premium
            let precioUnitario = precioBase;
            if(saborSeleccionado.esPremium) {
                precioUnitario += parseFloat(saborSeleccionado.precioPremium);
            }
            
            let datos = {
                tipo: 'producto_simple',
                producto_id: productoId,
                producto_nombre: 'Calzone ' + productoNombre + ' - ' + saborSeleccionado.nombre,
                precio: precioUnitario,
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
                    mostrarNotificacion('✓ Calzone agregado al carrito');
                    setTimeout(() => window.location.href = 'menu.php', 700);
                } else {
                    mostrarNotificacion('Error: ' + data.error, 'error');
                }
            })
            .catch(error => {
                mostrarNotificacion('Error al agregar', 'error');
                console.error(error);
            });
        }
    </script>
</body>
</html>


