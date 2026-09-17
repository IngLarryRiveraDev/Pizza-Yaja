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

    // Los batidos (7 y 8) no salen acá: se piden desde Cafetería
    $stmt = $conn->query("SELECT * FROM categorias WHERE id NOT IN (7, 8) ORDER BY orden ASC");
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                <?php if(in_array($cat['id'], [3, 4, 11])): ?>
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
</body>
</html>


