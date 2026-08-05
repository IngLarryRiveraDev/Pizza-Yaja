<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);
$action = $datos['action'] ?? '';
$id     = (int)($datos['id'] ?? 0);

if(!$id) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

require_once 'config.php';

try {
    $conn = getConnection();

    if($action === 'precio') {
        $precio = (int)($datos['precio'] ?? -1);
        if($precio < 0) {
            echo json_encode(['success' => false, 'error' => 'Precio inválido']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE productos SET precio = ? WHERE id = ?");
        $stmt->execute([$precio, $id]);
        echo json_encode(['success' => true]);

    } elseif($action === 'toggle') {
        $stmt = $conn->prepare("SELECT activo FROM productos WHERE id = ?");
        $stmt->execute([$id]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$prod) {
            echo json_encode(['success' => false, 'error' => 'Producto no encontrado']);
            exit;
        }
        $nuevo = $prod['activo'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE productos SET activo = ? WHERE id = ?");
        $stmt->execute([$nuevo, $id]);
        echo json_encode(['success' => true, 'activo' => $nuevo]);

    } else {
        echo json_encode(['success' => false, 'error' => 'Acción desconocida']);
    }

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
