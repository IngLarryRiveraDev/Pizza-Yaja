<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

require_once 'config.php';

$d = json_decode(file_get_contents('php://input'), true);

$concepto  = trim($d['concepto'] ?? '');
$categoria = $d['categoria'] ?? 'otros';
$monto     = $d['monto'] ?? null;
$metodo    = $d['metodo_pago'] ?? '';
$nota      = trim($d['nota'] ?? '');

$CATS = ['ingredientes','servicios','personal','equipos','marketing','otros'];

if($concepto === '' || !is_numeric($monto) || $monto <= 0) {
    echo json_encode(['success' => false, 'error' => 'Escribí el concepto y un monto válido']);
    exit;
}
if(!in_array($metodo, ['efectivo','sinpe','tarjeta'])) {
    echo json_encode(['success' => false, 'error' => 'Forma de pago inválida']);
    exit;
}
if(!in_array($categoria, $CATS)) $categoria = 'otros';

try {
    $conn = getConnection();

    $conn->exec("
      CREATE TABLE IF NOT EXISTS gastos (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        concepto    VARCHAR(200) NOT NULL,
        categoria   VARCHAR(50)  NOT NULL DEFAULT 'otros',
        monto       DECIMAL(10,2) NOT NULL,
        fecha       DATE NOT NULL,
        sucursal    VARCHAR(20)  NOT NULL DEFAULT 'cariari',
        metodo_pago ENUM('efectivo','sinpe','tarjeta') NOT NULL DEFAULT 'efectivo',
        nota        TEXT,
        usuario_id  INT,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    ");
    try { $conn->exec("ALTER TABLE gastos ADD COLUMN metodo_pago ENUM('efectivo','sinpe','tarjeta') NOT NULL DEFAULT 'efectivo' AFTER sucursal"); } catch(PDOException $e) {}

    $stmt = $conn->prepare("
        INSERT INTO gastos (concepto, categoria, monto, fecha, sucursal, metodo_pago, nota, usuario_id)
        VALUES (?,?,?,CURDATE(),?,?,?,?)
    ");
    $stmt->execute([
        $concepto,
        $categoria,
        $monto,
        $_SESSION['sucursal'] ?? 'cariari',
        $metodo,
        $nota !== '' ? $nota : null,
        $_SESSION['usuario_id']
    ]);

    echo json_encode(['success' => true]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error al guardar el gasto']);
}
