<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$orden_id = $_SESSION['orden_actual'] ?? 0;

require_once 'config.php';

$orden = null;
$items = [];

if($orden_id > 0) {
    try {
        $conn = getConnection();
        
        // Obtener información de la orden
        $stmt = $conn->prepare("SELECT * FROM ordenes WHERE id = ?");
        $stmt->execute([$orden_id]);
        $orden = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Obtener items de la orden
        $stmt = $conn->prepare("SELECT * FROM detalle_orden WHERE orden_id = ?");
        $stmt->execute([$orden_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        die("Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orden Actual - Pizza Yaja</title>
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
        .orden-vacia {
            background: white;
            padding: 40px;
            border-radius: 10px;
            text-align: center;
            color: #666;
        }
        .item-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .item-nombre {
            font-weight: bold;
            color: #ff9800;
            font-size: 16px;
        }
        .item-precio {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }
        .item-notas {
            background: #f5f5f5;
            padding: 8px;
            border-radius: 5px;
            font-size: 13px;
            color: #666;
            margin-top: 8px;
        }
        .btn-eliminar {
            background: #c62828;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
        }
        .total-section {
            background: #ff9800;
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: center;
        }
        .total-label {
            font-size: 18px;
            margin-bottom: 5px;
        }
        .total-monto {
            font-size: 32px;
            font-weight: bold;
        }
        .btn-finalizar {
            background: #4caf50;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            margin-bottom: 10px;
        }
        .btn-vaciar {
            background: #c62828;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Orden Actual <?php echo $orden ? '(#' . $orden['numero_orden'] . ')' : ''; ?> - <?php echo count($items); ?> items</h1>
        <a href="menu.php" class="back-btn">← Volver al Menú</a>
    </div>

    <?php if(!$orden || empty($items)): ?>
        <div class="orden-vacia">
            <h2>No hay productos en esta orden</h2>
            <p style="margin-top: 10px;">Agrega productos desde el menú</p>
        </div>
    <?php else: ?>
        <?php foreach($items as $item): ?>
            <div class="item-card">
                <div class="item-header">
                    <div class="item-nombre">
                        <?php if($item['cantidad'] > 1): ?>
                            <span style="background: #ff9800; color: white; padding: 3px 8px; border-radius: 3px; margin-right: 8px; font-size: 14px;">
                                <?php echo $item['cantidad']; ?>x
                            </span>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($item['producto_nombre']); ?>
                    </div>
                    <div class="item-precio">₡<?php echo number_format($item['precio_unitario'] * $item['cantidad'], 0); ?></div>
                </div>

                <?php if(!empty($item['notas'])): ?>
                    <div class="item-notas">
                        💬 <?php echo htmlspecialchars($item['notas']); ?>
                    </div>
                <?php endif; ?>

                <button class="btn-eliminar" onclick="eliminarItem(<?php echo $item['id']; ?>)">Eliminar</button>
            </div>
        <?php endforeach; ?>

        <div class="total-section">
            <div class="total-label">Total a Pagar</div>
            <div class="total-monto">₡<?php echo number_format($orden['total'], 0); ?></div>
        </div>

        <button class="btn-finalizar" onclick="finalizarOrden()">Finalizar Orden</button>
        <button class="btn-vaciar" onclick="vaciarOrden()">Vaciar Orden</button>
    <?php endif; ?>

    <script>
        function eliminarItem(itemId) {
            if(confirm('¿Eliminar este producto?')) {
                window.location.href = 'eliminar_item.php?item_id=' + itemId;
            }
        }

        function vaciarOrden() {
            if(confirm('¿Vaciar toda la orden?')) {
                window.location.href = 'vaciar_orden.php';
            }
        }

        function finalizarOrden() {
            window.location.href = 'finalizar_orden.php';
        }
    </script>
</body>
</html>

