<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// Recibir datos del tamaÃ±o seleccionado
$categoria_id = $_GET['categoria'] ?? 0;
$tamano_id = $_GET['tamano'] ?? 0;
$tamano_nombre = $_GET['nombre'] ?? '';
$precio_base = $_GET['precio'] ?? 0;

require_once 'config.php';

try {
    $conn = getConnection();
    
    // Obtener sabores de pizza (de la tabla productos donde categoria sea pizza)
    // Por ahora usaremos los que ya insertamos en categoria 2 (Pizza Individual)
    $stmt = $conn->prepare("SELECT * FROM productos WHERE categoria_id = 2 AND activo = 1 ORDER BY nombre");
    $stmt->execute();
    $sabores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}

$es_2x1 = ($categoria_id == 1);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seleccionar Sabor - Pizza Yaja</title>
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
        .info-pedido {
            color: #666;
            font-size: 14px;
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
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .seccion-titulo {
            font-size: 15px;
            font-weight: bold;
            color: #ff9800;
            margin-bottom: 10px;
        }
        .sabores-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 7px;
        }
        .sabor-btn {
            background: #f5f5f5;
            border: 2px solid #ddd;
            padding: 9px 4px;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
        }
        .sabor-btn:hover {
            border-color: #ff9800;
            background: #fff3e0;
        }
        .sabor-btn.selected {
            background: #ff9800;
            color: white;
            border-color: #ff9800;
        }
        .sabor-nombre {
            font-weight: bold;
            font-size: 13px;
            margin-bottom: 3px;
        }
        .premium-badge {
            background: #c62828;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
        }
        .opcion-mitad {
            margin: 15px 0;
        }
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .checkbox-container input[type="checkbox"] {
            width: 20px;
            height: 20px;
        }
        textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
            resize: vertical;
            font-family: Arial;
        }
        .btn-agregar {
            background: #4caf50;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
        }
        .btn-agregar:hover {
            background: #45a049;
        }
        .precio-total {
            background: #ff9800;
            color: white;
            padding: 10px;
            border-radius: 5px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Pizza <?php echo $es_2x1 ? '2x1' : 'Individual'; ?> - <?php echo htmlspecialchars($tamano_nombre); ?></h1>
        <div class="info-pedido">Precio base: ₡<?php echo number_format($precio_base, 0); ?></div>
        <a href="productos.php?categoria=<?php echo $categoria_id; ?>" class="back-btn">← Volver</a>
    </div>

    <?php if($es_2x1): ?>
        <!-- Para 2x1: seleccionar 2 pizzas -->
        <div class="seccion">
            <div class="seccion-titulo">Pizza 1 (Jamón y Queso incluido)</div>
            <div class="checkbox-container">
                <input type="checkbox" id="cambiar_pizza1" onchange="togglePizza1()">
                <label for="cambiar_pizza1">Cambiar a otro sabor (+₡1000)</label>
            </div>
            <div id="sabores_pizza1" style="display: none;">
                <div class="sabores-grid">
                    <?php foreach($sabores as $sabor): ?>
                        <div class="sabor-btn" onclick="seleccionarSabor(1, <?php echo $sabor['id']; ?>, '<?php echo addslashes($sabor['nombre']); ?>', <?php echo $sabor['es_premium']; ?>, <?php echo $sabor['precio_premium']; ?>)">
                            <div class="sabor-nombre">
                                <?php echo htmlspecialchars($sabor['nombre']); ?>
                                <?php if($sabor['es_premium']): ?>
                                    <span class="premium-badge">+<?php echo number_format($sabor['precio_premium']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="seccion">
            <div class="seccion-titulo">Pizza 2 (Sabor a elegir)</div>
            <div class="sabores-grid" id="sabores_pizza2">
                <?php foreach($sabores as $sabor): ?>
                    <div class="sabor-btn" onclick="seleccionarSabor(2, <?php echo $sabor['id']; ?>, '<?php echo addslashes($sabor['nombre']); ?>', <?php echo $sabor['es_premium']; ?>, <?php echo $sabor['precio_premium']; ?>)">
                        <div class="sabor-nombre">
                            <?php echo htmlspecialchars($sabor['nombre']); ?>
                            <?php if($sabor['es_premium']): ?>
                                <span class="premium-badge">+<?php echo number_format($sabor['precio_premium']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php else: ?>
        <!-- Para Individual: una pizza con opciÃ³n mitad/mitad -->
        <div class="seccion">
            <div class="seccion-titulo">Seleccionar Sabor</div>
            
            <div class="opcion-mitad">
                <div class="checkbox-container">
                    <input type="checkbox" id="mitad_mitad" onchange="toggleMitadMitad()">
                    <label for="mitad_mitad">Mitad y Mitad (dos sabores)</label>
                </div>
            </div>

            <div id="sabor_completo">
                <div class="sabores-grid">
                    <?php foreach($sabores as $sabor): ?>
                        <div class="sabor-btn" onclick="seleccionarSaborCompleto(<?php echo $sabor['id']; ?>, '<?php echo addslashes($sabor['nombre']); ?>', <?php echo $sabor['es_premium']; ?>, <?php echo $sabor['precio_premium']; ?>)">
                            <div class="sabor-nombre">
                                <?php echo htmlspecialchars($sabor['nombre']); ?>
                                <?php if($sabor['es_premium']): ?>
                                    <span class="premium-badge">+<?php echo number_format($sabor['precio_premium']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="mitades" style="display: none;">
                <p style="margin-bottom: 10px; font-weight: bold;">Mitad 1:</p>
                <div class="sabores-grid" style="margin-bottom: 20px;">
                    <?php foreach($sabores as $sabor): ?>
                        <div class="sabor-btn" onclick="seleccionarMitad(1, <?php echo $sabor['id']; ?>, '<?php echo addslashes($sabor['nombre']); ?>', <?php echo $sabor['es_premium']; ?>, <?php echo $sabor['precio_premium']; ?>)">
                            <div class="sabor-nombre">
                                <?php echo htmlspecialchars($sabor['nombre']); ?>
                                <?php if($sabor['es_premium']): ?>
                                    <span class="premium-badge">+<?php echo number_format($sabor['precio_premium']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p style="margin-bottom: 10px; font-weight: bold;">Mitad 2:</p>
                <div class="sabores-grid">
                    <?php foreach($sabores as $sabor): ?>
                        <div class="sabor-btn" onclick="seleccionarMitad(2, <?php echo $sabor['id']; ?>, '<?php echo addslashes($sabor['nombre']); ?>', <?php echo $sabor['es_premium']; ?>, <?php echo $sabor['precio_premium']; ?>)">
                            <div class="sabor-nombre">
                                <?php echo htmlspecialchars($sabor['nombre']); ?>
                                <?php if($sabor['es_premium']): ?>
                                    <span class="premium-badge">+<?php echo number_format($sabor['precio_premium']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Opciones comunes -->
    <div class="seccion">
        <div class="seccion-titulo">Opciones</div>
        <div class="checkbox-container">
            <input type="checkbox" id="para_llevar" name="para_llevar">
            <label for="para_llevar">Para llevar</label>
        </div>
        <div class="checkbox-container">
            <input type="checkbox" id="comer_aqui" name="comer_aqui" checked>
            <label for="comer_aqui">Comer aquí</label>
        </div>
    </div>

    <div class="seccion">
        <div class="seccion-titulo">Comentarios especiales</div>
        <textarea id="comentarios" rows="3" placeholder="Ej: Sin cebolla, bien cocida, etc."></textarea>
    </div>

    <div class="precio-total" id="precio_total">
        Total: ₡<?php echo number_format($precio_base, 0); ?>
    </div>

    <button class="btn-agregar" onclick="agregarAlCarrito()">Agregar al Carrito</button>

    <script>
        let precioBase = <?php echo $precio_base; ?>;
        let precioTotal = precioBase;
        let es2x1 = <?php echo $es_2x1 ? 'true' : 'false'; ?>;
        
        // Variables para guardar selecciÃ³n
        let seleccion = {
            pizza1: null,
            pizza2: null,
            cambio_pizza1: false,
            mitad1: null,
            mitad2: null,
            es_mitad_mitad: false
        };

        function togglePizza1() {
            let checked = document.getElementById('cambiar_pizza1').checked;
            document.getElementById('sabores_pizza1').style.display = checked ? 'block' : 'none';
            seleccion.cambio_pizza1 = checked;
            if(!checked) {
                seleccion.pizza1 = null;
                // Remover selecciÃ³n visual
                document.querySelectorAll('#sabores_pizza1 .sabor-btn').forEach(btn => btn.classList.remove('selected'));
            }
            calcularTotal();
        }

        function seleccionarSabor(pizza, id, nombre, esPremium, precioPremium) {
            // Remover selecciÃ³n anterior
            let contenedor = pizza == 1 ? '#sabores_pizza1' : '#sabores_pizza2';
            document.querySelectorAll(contenedor + ' .sabor-btn').forEach(btn => btn.classList.remove('selected'));
            
            // Marcar como seleccionado
            event.target.closest('.sabor-btn').classList.add('selected');
            
            if(pizza == 1) {
                seleccion.pizza1 = {id, nombre, esPremium, precioPremium};
            } else {
                seleccion.pizza2 = {id, nombre, esPremium, precioPremium};
            }
            calcularTotal();
        }

        function toggleMitadMitad() {
            let checked = document.getElementById('mitad_mitad').checked;
            document.getElementById('sabor_completo').style.display = checked ? 'none' : 'block';
            document.getElementById('mitades').style.display = checked ? 'block' : 'none';
            seleccion.es_mitad_mitad = checked;
            
            // Limpiar selecciones
            document.querySelectorAll('.sabor-btn').forEach(btn => btn.classList.remove('selected'));
            seleccion.pizza1 = null;
            seleccion.mitad1 = null;
            seleccion.mitad2 = null;
            calcularTotal();
        }

        function seleccionarSaborCompleto(id, nombre, esPremium, precioPremium) {
            document.querySelectorAll('#sabor_completo .sabor-btn').forEach(btn => btn.classList.remove('selected'));
            event.target.closest('.sabor-btn').classList.add('selected');
            seleccion.pizza1 = {id, nombre, esPremium, precioPremium};
            calcularTotal();
        }

        function seleccionarMitad(mitad, id, nombre, esPremium, precioPremium) {
            let contenedores = document.querySelectorAll('#mitades .sabores-grid');
            contenedores[mitad-1].querySelectorAll('.sabor-btn').forEach(btn => btn.classList.remove('selected'));
            event.target.closest('.sabor-btn').classList.add('selected');
            
            if(mitad == 1) {
                seleccion.mitad1 = {id, nombre, esPremium, precioPremium};
            } else {
                seleccion.mitad2 = {id, nombre, esPremium, precioPremium};
            }
            calcularTotal();
        }

        function calcularTotal() {
            precioTotal = precioBase;
            
            if(es2x1) {
                // Si cambiÃ³ pizza 1, +1000
                if(seleccion.cambio_pizza1) precioTotal += 1000;
                
                // Premium en pizza 1
                if(seleccion.pizza1 && seleccion.pizza1.esPremium) {
                    precioTotal += parseFloat(seleccion.pizza1.precioPremium);
                }
                
                // Premium en pizza 2
                if(seleccion.pizza2 && seleccion.pizza2.esPremium) {
                    precioTotal += parseFloat(seleccion.pizza2.precioPremium);
                }
            } else {
                // Individual
                if(seleccion.es_mitad_mitad) {
                    // Mitad y mitad: cobrar el premium mÃ¡s alto
                    let premium1 = (seleccion.mitad1 && seleccion.mitad1.esPremium) ? parseFloat(seleccion.mitad1.precioPremium) : 0;
                    let premium2 = (seleccion.mitad2 && seleccion.mitad2.esPremium) ? parseFloat(seleccion.mitad2.precioPremium) : 0;
                    precioTotal += Math.max(premium1, premium2);
                } else {
                    // Sabor completo
                    if(seleccion.pizza1 && seleccion.pizza1.esPremium) {
                        precioTotal += parseFloat(seleccion.pizza1.precioPremium);
                    }
                }
            }
            
            document.getElementById('precio_total').textContent = 'Total: ₡' + precioTotal.toLocaleString('es-CR');
        }

        function agregarAlCarrito() {
    // Validar selecciÃ³n
    if(es2x1) {
        if(!seleccion.pizza2) {
            mostrarNotificacion('Debes seleccionar el sabor de la Pizza 2', 'error');
            return;
        }
        if(seleccion.cambio_pizza1 && !seleccion.pizza1) {
            mostrarNotificacion('Debes seleccionar el sabor de la Pizza 1', 'error');
            return;
        }
    } else {
        if(seleccion.es_mitad_mitad) {
            if(!seleccion.mitad1 || !seleccion.mitad2) {
                mostrarNotificacion('Debes seleccionar ambas mitades', 'error');
                return;
            }
        } else {
            if(!seleccion.pizza1) {
                mostrarNotificacion('Debes seleccionar un sabor', 'error');
                return;
            }
        }
    }
    
    // Preparar datos para enviar
    let datos = {
        tipo: es2x1 ? 'pizza_2x1' : 'pizza_individual',
        categoria_id: <?php echo $categoria_id; ?>,
        tamano: '<?php echo $tamano_nombre; ?>',
        precio: precioTotal,
        detalles: seleccion,
        comentarios: document.getElementById('comentarios').value,
        para_llevar: document.getElementById('para_llevar').checked
    };
    
    // Enviar al servidor
    fetch('agregar_carrito.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(datos)
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            //alert('Â¡Producto agregado al carrito!');
            window.location.href = 'menu.php';
        }
    })
    .catch(error => {
        mostrarNotificacion('Error al agregar al carrito', 'error');
        console.error(error);
    });
}

        // Sincronizar checkboxes de para llevar / comer aquÃ­
        document.getElementById('para_llevar').addEventListener('change', function() {
            if(this.checked) document.getElementById('comer_aqui').checked = false;
        });
        document.getElementById('comer_aqui').addEventListener('change', function() {
            if(this.checked) document.getElementById('para_llevar').checked = false;
        });
    </script>
</body>
</html>


