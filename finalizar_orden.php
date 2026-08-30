<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$orden_id = $_SESSION['orden_actual'] ?? 0;

if($orden_id == 0) {
    header('Location: ordenes_activas.php');
    exit;
}

// ConexiÃ³n BD
require_once 'config.php';

try {
    $conn = getConnection();
    
    // Obtener informaciÃ³n de la orden
    $stmt = $conn->prepare("SELECT * FROM ordenes WHERE id = ?");
    $stmt->execute([$orden_id]);
    $orden = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$orden) {
        header('Location: ordenes_activas.php');
        exit;
    }
    
    // Verificar que tenga productos
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM detalle_orden WHERE orden_id = ?");
    $stmt->execute([$orden_id]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($count['total'] == 0) {
        die("No puedes finalizar una orden sin productos. <a href='menu.php'>Volver al menú</a>");
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
    <title>Finalizar Orden - Pizza Yaja</title>
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
        .container {
            max-width: 500px;
            margin: 50px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
        }
        h1 {
            color: #ff9800;
            text-align: center;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #ff9800;
        }
        .total-info {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .total-label {
            color: #666;
            font-size: 14px;
        }
        .total-monto {
            color: #ff9800;
            font-size: 28px;
            font-weight: bold;
            margin-top: 5px;
        }
        .btn-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .btn {
            padding: 15px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            text-align: center;
        }
        .btn-pagar-ahora {
            background: #4caf50;
            color: white;
        }
        .btn-pagar-despues {
            background: #ff9800;
            color: white;
        }
        .btn-cocina {
            background: #9c27b0;
            color: white;
        }
        .btn-cancelar {
            background: #666;
            color: white;
        }
        .btn:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Finalizar Orden #<?php echo $orden['numero_orden']; ?></h1>
        
        <div class="total-info">
            <div class="total-label">Total de la orden</div>
            <div class="total-monto">₡<?php echo number_format($orden['total'], 0); ?></div>
        </div>

        <form id="form_finalizar">
            <div class="form-group">
                <label>Nombre del Cliente o Número de Mesa:</label>
                <input type="text" id="nombre_mesa" name="nombre_mesa" required autofocus placeholder="Ej: Juan Pérez o Mesa 5" value="<?php echo htmlspecialchars($orden['nombre_cliente']); ?>" onfocus="limpiarNombreMesa()" onblur="restaurarNombreMesa()">
            </div>

            <div class="btn-container">
                <button type="button" class="btn btn-pagar-ahora" onclick="procesarOrden(true)">
                    💳 Pagar Ahora
                </button>

                <button type="button" class="btn btn-pagar-despues" onclick="procesarOrden(false)">
                    📝 Guardar y Pagar Después
                </button>
                
                <button type="button" class="btn btn-cocina" onclick="procesarOrden('cocina')">
                    🍳 Enviar a Cocina
                </button>

                <button type="button" class="btn btn-cancelar" onclick="window.location.href='ver_orden.php'">
                    Cancelar
                </button>
            </div>
        </form>
    </div>

    <script>
        function procesarOrden(accion) {
            let nombreMesa = document.getElementById('nombre_mesa').value.trim();

            if(!nombreMesa) {
                mostrarNotificacion('Debes ingresar el nombre del cliente o número de mesa', 'error');
                return;
            }

            fetch('actualizar_nombre_orden.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    orden_id: <?php echo $orden_id; ?>,
                    nombre_mesa: nombreMesa
                })
            })
            .then(r => r.json())
            .then(data => {
                if(!data.success) {
                    mostrarNotificacion('Error: ' + data.error, 'error');
                    return;
                }

                if(accion === true) {
                    window.location.href = 'pago.php?orden_id=<?php echo $orden_id; ?>';

                } else if(accion === 'cocina') {
                    fetch('enviar_cocina.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ orden_id: <?php echo $orden_id; ?> })
                    })
                    .then(r => r.json())
                    .then(res => {
                        if(res.success) {
                            mostrarNotificacion('Orden enviada a cocina.', 'success');
                            window.location.href = 'ordenes_activas.php';
                        } else {
                            mostrarNotificacion('Error: ' + res.error, 'error');
                        }
                    });

                } else {
                    mostrarNotificacion('Orden guardada. Puedes pagarla después desde Órdenes Activas.', 'success');
                    window.location.href = 'ordenes_activas.php';
                }
            })
            .catch(() => mostrarNotificacion('Error al procesar', 'error'));
        }

        function limpiarNombreMesa() {
            let input = document.getElementById('nombre_mesa');
            if(input.value === 'Sin asignar') {
                input.value = '';
            }
        }

        function restaurarNombreMesa() {
            let input = document.getElementById('nombre_mesa');
            if(input.value.trim() === '') {
                input.value = 'Sin asignar';
            }
        }
    </script>
</body>
</html>


