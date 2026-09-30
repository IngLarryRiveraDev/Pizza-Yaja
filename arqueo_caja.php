<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php'); exit;
}

require_once 'config.php';
require_once 'solicitudes_fn.php';

const ESPERA_RECHAZO_MIN = 3; // minutos antes de poder pedirlo de nuevo

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
            periodo_desde    DATETIME NULL,
            periodo_hasta    DATETIME NULL,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_usuario_fecha (usuario_id, fecha_cierre)
        )
    ");
    try { $conn->exec("ALTER TABLE arqueos ADD COLUMN periodo_desde DATETIME NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE arqueos ADD COLUMN periodo_hasta DATETIME NULL"); } catch(PDOException $e) {}
    foreach(['efectivo','sinpe','tarjeta'] as $m) {
        try { $conn->exec("ALTER TABLE arqueos ADD COLUMN gastos_{$m} DECIMAL(10,2) NOT NULL DEFAULT 0"); } catch(PDOException $e) {}
    }
} catch(PDOException $e) {}

// El turno arranca donde terminó el cierre anterior de esta sucursal
$periodo_desde = date('Y-m-d 00:00:00');
try {
    $ult = $conn->prepare("SELECT MAX(periodo_hasta) FROM arqueos WHERE sucursal = ?");
    $ult->execute([$sucursal]);
    $ultimo = $ult->fetchColumn();
    if($ultimo) $periodo_desde = $ultimo;
} catch(PDOException $e) {}

// Órdenes que siguen abiertas: no bloquean, pasan al siguiente turno
$ordenes_abiertas = [];
try {
    $chkOrd = $conn->prepare("
        SELECT numero_orden, nombre_cliente, total FROM ordenes
        WHERE sucursal = ? AND estado IN ('pendiente','en_cocina','listo','pagado')
        ORDER BY numero_orden
    ");
    $chkOrd->execute([$sucursal]);
    $ordenes_abiertas = $chkOrd->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {}

// Verificar si ya envió arqueo hoy
try {
    $chk = $conn->prepare("SELECT id FROM arqueos WHERE usuario_id = ? AND fecha_cierre = ?");
    $chk->execute([$usuario_id, $fecha_hoy]);
    if($chk->fetch()) {
        $error = 'ya_enviado';
    }
} catch(PDOException $e) {}

// ── Permiso de la administración para hacer el cierre ────────────────────────
setupSolicitudes($conn);

function ultimaSolicitudCierre($conn, $usuario_id) {
    // Solo del día: una autorización de ayer no sirve para cerrar hoy
    $q = $conn->prepare("
        SELECT * FROM solicitudes
        WHERE tipo = 'cierre' AND usuario_id = ? AND estado <> 'usada'
          AND DATE(created_at) = CURDATE()
        ORDER BY created_at DESC LIMIT 1
    ");
    $q->execute([$usuario_id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Pedir permiso
if(($_POST['accion'] ?? '') === 'solicitar' && !$error) {
    $previa = ultimaSolicitudCierre($conn, $usuario_id);
    $puede  = true;
    if($previa && $previa['estado'] === 'pendiente') $puede = false;
    if($previa && $previa['estado'] === 'rechazada' && $previa['respondido_at']) {
        $espera = strtotime($previa['respondido_at']) + ESPERA_RECHAZO_MIN * 60;
        if(time() < $espera) $puede = false;
    }
    if($puede) {
        $conn->prepare("
            INSERT INTO solicitudes (tipo, usuario_id, sucursal, motivo)
            VALUES ('cierre', ?, ?, 'Solicita hacer el cierre de caja')
        ")->execute([$usuario_id, $sucursal]);
    }
    header('Location: arqueo_caja.php'); exit;
}

$solicitud  = ultimaSolicitudCierre($conn, $usuario_id);
$aprobado   = $solicitud && $solicitud['estado'] === 'aprobada';
$esperando  = $solicitud && $solicitud['estado'] === 'pendiente';
$rechazado  = $solicitud && $solicitud['estado'] === 'rechazada';

// Segundos que faltan para poder volver a pedirlo
$espera_seg = 0;
if($rechazado && $solicitud['respondido_at']) {
    $espera_seg = max(0, strtotime($solicitud['respondido_at']) + ESPERA_RECHAZO_MIN * 60 - time());
}

// Procesar envío
if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar' && !$error && $aprobado) {
    $fisico_efectivo = (float)str_replace(',', '.', $_POST['efectivo'] ?? 0);
    $fisico_sinpe    = (float)str_replace(',', '.', $_POST['sinpe']    ?? 0);
    $fisico_tarjeta  = (float)str_replace(',', '.', $_POST['tarjeta']  ?? 0);
    $notas           = trim($_POST['notas'] ?? '');

    $periodo_hasta = date('Y-m-d H:i:s');

    try {
        // Lo cobrado durante este turno, por la hora real del pago
        $stmt = $conn->prepare("
            SELECT p.metodo_pago, COALESCE(SUM(p.monto_aplicado), 0) AS total
            FROM pagos p
            JOIN ordenes o ON p.orden_id = o.id
            WHERE o.sucursal = ?
              AND p.fecha_pago >  ?
              AND p.fecha_pago <= ?
            GROUP BY p.metodo_pago
        ");
        $stmt->execute([$sucursal, $periodo_desde, $periodo_hasta]);
        $sys = ['efectivo'=>0,'sinpe'=>0,'tarjeta'=>0];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $sys[$r['metodo_pago']] = (float)$r['total'];

        // Lo que salió de caja durante el turno
        $gas = ['efectivo'=>0,'sinpe'=>0,'tarjeta'=>0];
        try {
            $gq = $conn->prepare("
                SELECT metodo_pago, COALESCE(SUM(monto), 0) AS total
                FROM gastos
                WHERE sucursal = ?
                  AND created_at >  ?
                  AND created_at <= ?
                GROUP BY metodo_pago
            ");
            $gq->execute([$sucursal, $periodo_desde, $periodo_hasta]);
            foreach($gq->fetchAll(PDO::FETCH_ASSOC) as $r) $gas[$r['metodo_pago']] = (float)$r['total'];
        } catch(PDOException $e) {}

        // Lo que debería haber en caja: lo cobrado menos los gastos del turno
        $esperado = [
            'efectivo' => $sys['efectivo'] - $gas['efectivo'],
            'sinpe'    => $sys['sinpe']    - $gas['sinpe'],
            'tarjeta'  => $sys['tarjeta']  - $gas['tarjeta'],
        ];

        $ins = $conn->prepare("
            INSERT INTO arqueos
                (usuario_id, sucursal, fecha_cierre,
                 fisico_efectivo, fisico_sinpe, fisico_tarjeta,
                 sistema_efectivo, sistema_sinpe, sistema_tarjeta,
                 gastos_efectivo, gastos_sinpe, gastos_tarjeta, notas,
                 periodo_desde, periodo_hasta)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $usuario_id, $sucursal, $fecha_hoy,
            $fisico_efectivo, $fisico_sinpe, $fisico_tarjeta,
            $esperado['efectivo'], $esperado['sinpe'], $esperado['tarjeta'],
            $gas['efectivo'], $gas['sinpe'], $gas['tarjeta'],
            $notas,
            $periodo_desde, $periodo_hasta
        ]);

        // El permiso se consume: no sirve para un segundo cierre
        $conn->prepare("UPDATE solicitudes SET estado = 'usada' WHERE id = ?")
             ->execute([$solicitud['id']]);

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

    <?php elseif($esperando): ?>
        <div class="estado-ok">
            <div class="icon">⏳</div>
            <h2 style="color:#ff9800">Esperando respuesta</h2>
            <p>La administración tiene que autorizar tu cierre.<br>Esta pantalla se actualiza sola.</p>
            <div style="color:#aaa;font-size:12px;margin-bottom:16px;">
                Enviada a las <?= date('H:i', strtotime($solicitud['created_at'])) ?>
            </div>
            <a href="ordenes_activas.php" class="btn-volver" style="margin-top:0">← Seguir trabajando</a>
        </div>
        <script>setTimeout(() => location.reload(), 5000);</script>

    <?php elseif(!$aprobado): ?>
        <?php if($rechazado): ?>
        <div class="alerta" style="border-color:#e53935">
            <div class="icon">🚫</div>
            <p><strong>La administración rechazó tu solicitud.</strong>
            <?php if($solicitud['respuesta']): ?>
                <br><span style="color:#c62828"><?= htmlspecialchars($solicitud['respuesta']) ?></span>
            <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <p style="color:#888;font-size:13px;text-align:center;margin-bottom:16px;">
            Para cerrar caja primero pedí autorización a la administración.
        </p>

        <?php if($espera_seg > 0): ?>
            <div style="background:#f5f5f5;border-radius:10px;padding:16px;text-align:center;margin-bottom:12px;">
                <div style="color:#888;font-size:13px;">Podés volver a pedirlo en</div>
                <div id="cuenta" style="font-size:28px;font-weight:bold;color:#555;font-family:monospace;">--:--</div>
            </div>
            <script>
            let restan = <?= (int)$espera_seg ?>;
            const pinta = () => {
                const m = String(Math.floor(restan / 60)).padStart(2,'0');
                const s = String(restan % 60).padStart(2,'0');
                document.getElementById('cuenta').textContent = m + ':' + s;
                if(restan-- <= 0) location.reload();
            };
            pinta();
            setInterval(pinta, 1000);
            </script>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="accion" value="solicitar">
                <button type="submit" class="btn-enviar">Pedir autorización →</button>
            </form>
        <?php endif; ?>

        <a href="ordenes_activas.php" class="btn-volver">← Volver</a>

    <?php else: ?>
        <div style="background:#e8f5e9;border:2px solid #4caf50;border-radius:10px;padding:10px;text-align:center;margin-bottom:14px;">
            <span style="color:#2e7d32;font-size:13px;font-weight:bold;">✓ Autorizado por la administración</span>
        </div>
        <p style="color:#888;font-size:13px;text-align:center;margin-bottom:8px;">
            Ingresá los montos físicos contados al cierre.
        </p>
        <p style="color:#aaa;font-size:12px;text-align:center;margin-bottom:16px;">
            Tu turno cuenta desde <?= date('d/m H:i', strtotime($periodo_desde)) ?>
        </p>

        <?php if(!empty($ordenes_abiertas)): ?>
        <div style="background:#e3f2fd;border:2px solid #90caf9;border-radius:10px;padding:12px;margin-bottom:16px;">
            <div style="font-size:13px;font-weight:bold;color:#1565c0;margin-bottom:8px;">
                🔄 <?= count($ordenes_abiertas) ?> orden<?= count($ordenes_abiertas) > 1 ? 'es' : '' ?> queda<?= count($ordenes_abiertas) > 1 ? 'n' : '' ?> abierta<?= count($ordenes_abiertas) > 1 ? 's' : '' ?>
            </div>
            <div style="font-size:12px;color:#555;line-height:1.7;">
                <?php foreach($ordenes_abiertas as $oa): ?>
                    <div>
                        <strong>#<?= (int)$oa['numero_orden'] ?></strong>
                        <?= htmlspecialchars($oa['nombre_cliente'] ?: 'Sin asignar') ?>
                        — ₡<?= number_format($oa['total'], 0) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="font-size:12px;color:#1565c0;margin-top:8px;">
                No las borres: pasan al siguiente turno y lo que se cobre ahí cuenta para el otro camarero.
            </div>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="accion" value="enviar">
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
