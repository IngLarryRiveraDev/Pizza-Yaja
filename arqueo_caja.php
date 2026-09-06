<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php'); exit;
}

require_once 'config.php';

$usuario_id = $_SESSION['usuario_id'];
$sucursal   = $_SESSION['sucursal'] ?? 'cariari';
$fecha_hoy  = date('Y-m-d');
$error      = '';
$enviado    = false;

// Auto-crear tabla si no existe
try {
    $conn = getConnection();
    $conn->exec("
        CREATE TABLE IF NOT EXISTS arqueos (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id       INT NOT NULL,
            sucursal         VARCHAR(20) NOT NULL DEFAULT 'cariari',
            fecha_cierre     DATE NOT NULL,
            fisico_efectivo  DECIMAL(10,2) NOT NULL DEFAULT 0,
            fisico_sinpe     DECIMAL(10,2) NOT NULL DEFAULT 0,
            fisico_tarjeta   DECIMAL(10,2) NOT NULL DEFAULT 0,
            sistema_efectivo DECIMAL(10,2) NOT NULL DEFAULT 0,
            sistema_sinpe    DECIMAL(10,2) NOT NULL DEFAULT 0,
            sistema_tarjeta  DECIMAL(10,2) NOT NULL DEFAULT 0,
            notas            TEXT,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_usuario_fecha (usuario_id, fecha_cierre)
        )
    ");
} catch(PDOException $e) {}

// Verificar si ya envió arqueo hoy
try {
    $chk = $conn->prepare("SELECT id FROM arqueos WHERE usuario_id = ? AND fecha_cierre = ?");
    $chk->execute([$usuario_id, $fecha_hoy]);
    if($chk->fetch()) {
        $error = 'ya_enviado';
    }
} catch(PDOException $e) {}

// Procesar envío
if($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $fisico_efectivo = (float)str_replace(',', '.', $_POST['efectivo'] ?? 0);
    $fisico_sinpe    = (float)str_replace(',', '.', $_POST['sinpe']    ?? 0);
    $fisico_tarjeta  = (float)str_replace(',', '.', $_POST['tarjeta']  ?? 0);
    $notas           = trim($_POST['notas'] ?? '');

    try {
        // Capturar totales del sistema server-side
        $stmt = $conn->prepare("
            SELECT p.metodo_pago, COALESCE(SUM(p.monto_aplicado), 0) AS total
            FROM pagos p
            JOIN ordenes o ON p.orden_id = o.id
            WHERE o.estado = 'completado'
              AND DATE(o.fecha_creacion) = ?
              AND o.sucursal = ?
            GROUP BY p.metodo_pago
        ");
        $stmt->execute([$fecha_hoy, $sucursal]);
        $sys = ['efectivo'=>0,'sinpe'=>0,'tarjeta'=>0];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $sys[$r['metodo_pago']] = (float)$r['total'];

        $ins = $conn->prepare("
            INSERT INTO arqueos
                (usuario_id, sucursal, fecha_cierre,
                 fisico_efectivo, fisico_sinpe, fisico_tarjeta,
                 sistema_efectivo, sistema_sinpe, sistema_tarjeta, notas)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $usuario_id, $sucursal, $fecha_hoy,
            $fisico_efectivo, $fisico_sinpe, $fisico_tarjeta,
            $sys['efectivo'], $sys['sinpe'], $sys['tarjeta'],
            $notas
        ]);
        $enviado = true;
    } catch(PDOException $e) {
        if($e->getCode() == 23000) {
            $error = 'ya_enviado';
        } else {
            $error = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$suc_nombre = ['cariari'=>'Cariari','guapiles'=>'Guapiles'][$sucursal] ?? ucfirst($sucursal);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cierre de Caja - Pizza Yaja</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: Arial, sans-serif; background:#1a1a1a; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px; }

        .card {
            background:white; border-radius:16px; padding:28px 24px;
            width:100%; max-width:400px; box-shadow:0 8px 32px rgba(0,0,0,.4);
        }
        .logo { text-align:center; margin-bottom:20px; }
        .logo h1 { color:#ff9800; font-size:22px; }
        .logo .sub { color:#888; font-size:13px; margin-top:4px; }
        .sucursal-badge {
            display:inline-block; background:#fff3e0; color:#ff9800;
            border:1px solid #ffcc80; border-radius:20px;
            padding:3px 12px; font-size:12px; font-weight:bold; margin-top:6px;
        }

        .field { margin-bottom:16px; }
        .field label { display:block; font-size:13px; font-weight:bold; color:#555; margin-bottom:6px; }
        .field input, .field textarea {
            width:100%; padding:14px; border:2px solid #e0e0e0; border-radius:10px;
            font-size:20px; text-align:center; font-family:Arial;
            transition:border-color .2s;
        }
        .field input:focus, .field textarea:focus { border-color:#ff9800; outline:none; }
        .field textarea { font-size:14px; text-align:left; resize:none; }

        .prefix { position:relative; }
        .prefix span {
            position:absolute; left:14px; top:50%; transform:translateY(-50%);
            font-size:18px; color:#999; font-weight:bold; pointer-events:none;
        }
        .prefix input { padding-left:32px; }
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }

        .btn-enviar {
            width:100%; padding:16px; background:#ff9800; color:white;
            border:none; border-radius:10px; font-size:17px; font-weight:bold;
            cursor:pointer; margin-top:8px; transition:background .2s;
        }
        .btn-enviar:hover { background:#e68900; }

        .divider { border:none; border-top:1px solid #f0f0f0; margin:16px 0; }

        /* Estados */
        .estado-ok { text-align:center; }
        .estado-ok .icon { font-size:64px; margin-bottom:12px; }
        .estado-ok h2 { color:#4caf50; font-size:20px; margin-bottom:8px; }
        .estado-ok p { color:#666; font-size:14px; margin-bottom:20px; }
        .btn-salir {
            display:block; width:100%; padding:14px; background:#c62828; color:white;
            border:none; border-radius:10px; font-size:16px; font-weight:bold;
            cursor:pointer; text-decoration:none; text-align:center;
        }

        .alerta {
            background:#fff3e0; border:2px solid #ff9800; border-radius:10px;
            padding:16px; text-align:center; margin-bottom:16px;
        }
        .alerta .icon { font-size:36px; margin-bottom:8px; }
        .alerta p { color:#555; font-size:14px; }
        .btn-volver {
            display:block; width:100%; padding:12px; background:#666; color:white;
            border:none; border-radius:10px; font-size:15px; font-weight:bold;
            cursor:pointer; text-decoration:none; text-align:center; margin-top:12px;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <h1>💰 Cierre de Caja</h1>
        <div class="sub"><?php echo date('d/m/Y'); ?></div>
        <span class="sucursal-badge">📍 <?php echo $suc_nombre; ?></span>
    </div>

    <?php if($enviado): ?>
        <div class="estado-ok">
            <div class="icon">✅</div>
            <h2>Cierre enviado</h2>
            <p>Los datos fueron enviados a la administración correctamente.</p>
            <a href="logout.php" class="btn-salir">Cerrar sesión</a>
        </div>

    <?php elseif($error === 'ya_enviado'): ?>
        <div class="alerta">
            <div class="icon">⚠️</div>
            <p><strong>Ya enviaste el cierre de hoy.</strong><br>Si hay un error, contactá a la administración.</p>
        </div>
        <a href="ordenes_activas.php" class="btn-volver">← Volver</a>

    <?php else: ?>
        <p style="color:#888;font-size:13px;text-align:center;margin-bottom:20px;">
            Ingresá los montos físicos contados al cierre.
        </p>

        <form method="POST">
            <div class="field">
                <label>💵 Efectivo en caja</label>
                <div class="prefix">
                    <span>₡</span>
                    <input type="number" name="efectivo" min="0" step="1" placeholder="0" inputmode="numeric" required>
                </div>
            </div>

            <div class="field">
                <label>📌 SINPE recibido</label>
                <div class="prefix">
                    <span>₡</span>
                    <input type="number" name="sinpe" min="0" step="1" placeholder="0" inputmode="numeric" required>
                </div>
            </div>

            <div class="field">
                <label>💳 Tarjeta (datáfono)</label>
                <div class="prefix">
                    <span>₡</span>
                    <input type="number" name="tarjeta" min="0" step="1" placeholder="0" inputmode="numeric" required>
                </div>
            </div>

            <hr class="divider">

            <div class="field">
                <label>📝 Notas (opcional)</label>
                <textarea name="notas" rows="2" placeholder="Alguna observación del cierre..."></textarea>
            </div>

            <button type="submit" class="btn-enviar">Enviar cierre →</button>
        </form>

        <a href="ordenes_activas.php" class="btn-volver" style="margin-top:10px;">← Cancelar</a>
    <?php endif; ?>
</div>
</body>
</html>
