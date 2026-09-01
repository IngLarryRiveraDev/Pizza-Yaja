<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';
require_once 'migrations.php';

try {
    $conn = getConnection();
    setupSucursalColumns($conn);

    $sucursal = $_SESSION['sucursal'] ?? 'cariari';
    $rol      = $_SESSION['rol']      ?? 'camarero';

    if($rol === 'admin' || $sucursal === 'ambas') {
        $stmt = $conn->query("
            SELECT * FROM ordenes
            WHERE estado IN ('pendiente', 'en_cocina', 'listo', 'pagado')
            ORDER BY fecha_creacion DESC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT * FROM ordenes
            WHERE estado IN ('pendiente', 'en_cocina', 'listo', 'pagado')
              AND sucursal = ?
            ORDER BY fecha_creacion DESC
        ");
        $stmt->execute([$sucursal]);
    }
    $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Construir datos completos para JS
    $ordenes_data = [];
    foreach($ordenes as $orden) {
        $stmt2 = $conn->prepare("SELECT * FROM detalle_orden WHERE orden_id = ?");
        $stmt2->execute([$orden['id']]);
        $detalles = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $stmt3 = $conn->prepare("SELECT SUM(monto_aplicado) as pagado FROM pagos WHERE orden_id = ?");
        $stmt3->execute([$orden['id']]);
        $pago_info = $stmt3->fetch(PDO::FETCH_ASSOC);
        $esta_pagada = ($pago_info['pagado'] >= $orden['total']);

        $ordenes_data[] = [
            'id'             => (int)$orden['id'],
            'numero_orden'   => (int)$orden['numero_orden'],
            'nombre_cliente' => $orden['nombre_cliente'],
            'tipo_servicio'  => $orden['tipo_servicio'],
            'total'          => (float)$orden['total'],
            'estado'         => $orden['estado'],
            'fecha_creacion' => $orden['fecha_creacion'],
            'esta_pagada'    => $esta_pagada,
            'detalles'       => $detalles
        ];
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
    <title>Órdenes Activas - Pizza Yaja</title>
    <script src="notificacion.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #1a1a1a; padding: 12px; }

        /* ── HEADER ── */
        .header {
            background: white;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .header h1 { color: #ff9800; font-size: 18px; }
        .back-btn {
            background: #4caf50; color: white;
            padding: 8px 12px; border-radius: 5px;
            text-decoration: none; font-weight: bold; font-size: 13px;
        }
        .btn-calzone-rapido {
            background: #ff9800; color: white;
            padding: 8px 12px; border-radius: 5px;
            text-decoration: none; font-weight: bold; font-size: 13px;
        }

        /* ── TARJETAS COMPACTAS ── */
        .ordenes-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }

        .orden-card-compact {
            background: white;
            border-top: 3px solid #ff9800;
            border-radius: 7px;
            padding: 6px 4px;
            cursor: pointer;
            transition: background 0.2s;
            text-align: center;
            overflow: hidden;
        }
        .orden-card-compact:active { background: #fff8f0; }
        .orden-card-compact.pagado    { border-top-color: #4caf50; }
        .orden-card-compact.en-cocina { border-top-color: #9c27b0; }
        .orden-card-compact.lista     { border-top-color: #4caf50; background: #f1fff1; }

        .compact-num { font-size: 11px; font-weight: bold; color: #ff9800; }
        .compact-cliente { font-size: 9px; color: #666; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .compact-badges { display: flex; flex-wrap: wrap; gap: 2px; justify-content: center; margin: 4px 0; }
        .estado-badge {
            display: inline-block; padding: 2px 4px;
            border-radius: 4px; font-size: 9px; font-weight: bold;
            white-space: nowrap;
        }
        .badge-pendiente    { background: #ff9800; color: white; }
        .badge-pagado       { background: #4caf50; color: white; }
        .badge-cocina       { background: #9c27b0; color: white; }
        .badge-listo-cocina { background: #4caf50; color: white; }

        .compact-timer { font-size: 10px; font-weight: bold; color: #333; font-family: monospace; }
        .compact-total { font-size: 11px; font-weight: bold; color: #ff9800; margin-top: 2px; }

        /* ── MODAL DETALLE ORDEN ── */
        .modal-overlay {
            display: none; position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8); z-index: 1000; overflow-y: auto;
        }
        .modal-box {
            max-width: 520px; margin: 20px auto;
            background: white; border-radius: 10px; padding: 15px;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 12px; padding-bottom: 10px; border-bottom: 2px solid #f5f5f5;
        }
        .modal-titulo { font-size: 18px; font-weight: bold; color: #ff9800; }
        .modal-close {
            background: none; border: none; font-size: 22px;
            cursor: pointer; color: #666; padding: 0 4px;
        }
        .modal-info { font-size: 13px; color: #555; margin-bottom: 4px; }
        .modal-info span { font-weight: bold; color: #333; }
        .modal-detalles {
            background: #f5f5f5; padding: 10px;
            border-radius: 5px; margin: 10px 0; font-size: 13px;
        }
        .detalle-item {
            padding: 5px 0; border-bottom: 1px solid #ddd;
        }
        .detalle-item:last-child { border-bottom: none; }
        .modal-total {
            font-size: 18px; font-weight: bold;
            color: #ff9800; text-align: right; margin: 8px 0;
        }
        .modal-btns { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
        .btn {
            flex: 1; padding: 10px 4px; border: none;
            border-radius: 5px; font-size: 13px;
            font-weight: bold; cursor: pointer; min-width: 70px;
        }
        .btn-cocina    { background: #9c27b0; color: white; }
        .btn-pagar     { background: #4caf50; color: white; }
        .btn-completar { background: #2196f3; color: white; }
        .btn-ver       { background: #666;    color: white; }
        .btn-eliminar  { background: #c62828; color: white; }
        .btn-cerrar    { background: #999;    color: white; width: 100%; margin-top: 6px; padding: 10px; border: none; border-radius: 5px; font-size: 14px; cursor: pointer; }

        .no-ordenes {
            background: white; padding: 40px;
            border-radius: 10px; text-align: center; color: #666;
        }
    </style>
</head>
<body>

<div class="header">
    <h1>Órdenes Activas (<?php echo count($ordenes); ?>)</h1>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="nueva_orden.php" class="back-btn">+ Nueva Orden</a>
        <a href="#" onclick="calzoneRapido(); return false;" class="btn-calzone-rapido">🧀 Calzone ₡1000</a>
        <a href="#" onclick="abrirArqueo(); return false;" style="background:#ff9800; color:white; padding:8px 12px; border-radius:5px; text-decoration:none; font-weight:bold; font-size:13px;">💰 Arqueo</a>
        <a href="logout.php" style="background:#c62828; color:white; padding:8px 12px; border-radius:5px; text-decoration:none; font-weight:bold; font-size:13px;">Salir</a>
    </div>
</div>

<?php if(empty($ordenes)): ?>
    <div class="no-ordenes">
        <h2>No hay órdenes activas</h2>
        <p style="margin-top:10px;">Las órdenes aparecerán aquí cuando se creen</p>
    </div>
<?php else: ?>
    <div class="ordenes-grid">
        <?php foreach($ordenes_data as $o): ?>
            <?php
            $clase = '';
            if($o['esta_pagada'])           $clase = 'pagado';
            if($o['estado'] == 'en_cocina') $clase = 'en-cocina';
            if($o['estado'] == 'listo')     $clase = 'lista';
            $nombre = ($o['nombre_cliente'] && !in_array($o['nombre_cliente'], ['Sin asignar', 'Orden Automática']))
                ? htmlspecialchars($o['nombre_cliente']) : '';
            ?>
            <div class="orden-card-compact <?php echo $clase; ?>"
                 onclick="abrirOrden(<?php echo $o['id']; ?>)"
                 data-fecha="<?php echo $o['fecha_creacion']; ?>"
                 data-id="<?php echo $o['id']; ?>">

                <div class="compact-num">Orden #<?php echo $o['numero_orden']; ?></div>
                <?php if($nombre): ?>
                    <div class="compact-cliente"><?php echo $nombre; ?></div>
                <?php endif; ?>

                <div class="compact-badges">
                    <span class="estado-badge <?php echo $o['esta_pagada'] ? 'badge-pagado' : 'badge-pendiente'; ?>">
                        <?php echo $o['esta_pagada'] ? '✓ Pagado' : '⏳ Pend.'; ?>
                    </span>
                    <?php if($o['estado'] == 'en_cocina'): ?>
                        <span class="estado-badge badge-cocina">👨‍🍳 Cocina</span>
                    <?php elseif($o['estado'] == 'listo'): ?>
                        <span class="estado-badge badge-listo-cocina">✅ Listo</span>
                    <?php endif; ?>
                </div>

                <div class="compact-timer" id="timer_<?php echo $o['id']; ?>">00:00:00</div>
                <div class="compact-total">₡<?php echo number_format($o['total'], 0); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal detalle de orden -->
<div class="modal-overlay" id="modal_orden">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-titulo" id="modal_titulo"></div>
            <button class="modal-close" onclick="cerrarModalOrden()">✕</button>
        </div>
        <div id="modal_body"></div>
        <div class="modal-btns" id="modal_btns"></div>
        <button class="btn-cerrar" onclick="cerrarModalOrden()">Cerrar</button>
    </div>
</div>

<!-- Modal Calzone Rápido -->
<div id="modal_calzone_rapido" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:1000;">
    <div style="max-width:400px; margin:30px auto; background:white; border-radius:10px; padding:15px;">
        <h2 style="color:#ff9800; margin-bottom:12px; text-align:center; font-size:18px;">🧀 Calzone Rápido</h2>
        <div style="text-align:center; margin-bottom:12px;">
            <div style="font-size:14px; color:#666; margin-bottom:6px;">Calzone Jamón y Queso</div>
            <div style="font-size:22px; font-weight:bold; color:#ff9800;">₡1,000 c/u</div>
        </div>
        <div style="margin-bottom:12px;">
            <label style="display:block; margin-bottom:6px; font-weight:bold; font-size:14px;">Cantidad:</label>
            <div style="display:flex; align-items:center; gap:8px;">
                <button onclick="cambiarCantidadCalzone(-1)" style="width:46px; height:46px; font-size:24px; border:2px solid #ddd; border-radius:5px; background:#f5f5f5; cursor:pointer;">−</button>
                <input type="number" id="calzone_cantidad" value="1" min="1" onchange="actualizarTotalCalzone()" style="flex:1; padding:10px; border:2px solid #ddd; border-radius:5px; font-size:20px; text-align:center;">
                <button onclick="cambiarCantidadCalzone(1)" style="width:46px; height:46px; font-size:24px; border:2px solid #ddd; border-radius:5px; background:#f5f5f5; cursor:pointer;">+</button>
            </div>
        </div>
        <div id="calzone_total" style="background:#ff9800; color:white; padding:10px; border-radius:5px; text-align:center; font-size:20px; font-weight:bold; margin-bottom:12px;">
            Total: ₡1,000
        </div>
        <button onclick="confirmarCalzoneRapido()" style="width:100%; padding:12px; background:#4caf50; color:white; border:none; border-radius:5px; font-size:16px; font-weight:bold; cursor:pointer; margin-bottom:8px;">
            Crear Orden y Pagar
        </button>
        <button onclick="cerrarModalCalzone()" style="width:100%; padding:10px; background:#666; color:white; border:none; border-radius:5px; font-size:14px; cursor:pointer;">
            Cancelar
        </button>
    </div>
</div>

<!-- Modal eliminar con motivo -->
<div id="modal_eliminar" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:2000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:10px;width:90%;max-width:400px;padding:20px">
        <h3 style="color:#c62828;margin-bottom:4px">🗑️ Eliminar orden</h3>
        <p id="elimModal_orden" style="font-weight:bold;color:#333;margin-bottom:12px"></p>
        <p style="font-size:13px;color:#555;margin-bottom:10px">Esta orden tiene productos. Indicá el motivo de eliminación para que quede registrado.</p>
        <input type="hidden" id="elimModal_id">
        <textarea id="elimModal_motivo" rows="3"
            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;resize:vertical;font-family:inherit"
            placeholder="Ej: Cliente canceló el pedido, Error al tomar la orden..."></textarea>
        <p id="elimModal_err" style="display:none;color:#c62828;font-size:12px;margin-top:4px">El motivo es obligatorio.</p>
        <div style="display:flex;gap:8px;margin-top:12px">
            <button onclick="document.getElementById('modal_eliminar').style.display='none'"
                style="flex:1;padding:10px;background:#999;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:bold;cursor:pointer">
                Cancelar
            </button>
            <button onclick="confirmarEliminar()"
                style="flex:1;padding:10px;background:#c62828;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:bold;cursor:pointer">
                Eliminar
            </button>
        </div>
    </div>
</div>

<script>
const ordersData = <?php echo json_encode($ordenes_data); ?>;

let ordenModalActual = null;

// ── CRONÓMETROS ──
document.querySelectorAll('.orden-card-compact').forEach(card => {
    const id = card.dataset.id;
    const fecha = new Date(card.dataset.fecha);
    const el = document.getElementById('timer_' + id);
    function tick() {
        const diff = Math.floor((new Date() - fecha) / 1000);
        const h = String(Math.floor(diff / 3600)).padStart(2, '0');
        const m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
        const s = String(diff % 60).padStart(2, '0');
        el.textContent = h + ':' + m + ':' + s;
    }
    tick();
    setInterval(tick, 1000);
});

// ── MODAL ORDEN ──
function abrirOrden(id) {
    const o = ordersData.find(x => x.id === id);
    if(!o) return;
    ordenModalActual = o;

    document.getElementById('modal_titulo').textContent = 'Orden #' + o.numero_orden;

    // Info
    let clienteNombre = o.nombre_cliente || 'Sin asignar';
    let servicio = o.tipo_servicio === 'express' ? '📦 Para llevar' : '🍽️ Comer aquí';
    let body = `
        <div class="modal-info">Cliente: <span>${clienteNombre}</span></div>
        <div class="modal-info">Servicio: <span>${servicio}</span></div>
        <div class="modal-detalles">`;

    o.detalles.forEach(d => {
        body += `<div class="detalle-item">`;
        if(d.cantidad > 1) {
            body += `<span style="background:#ff9800; color:white; padding:2px 6px; border-radius:3px; font-size:11px; margin-right:5px; font-weight:bold;">${d.cantidad}x</span>`;
        }
        body += `<strong>${d.producto_nombre}</strong>`;
        if(d.notas) body += `<br><small style="color:#666;">💬 ${d.notas}</small>`;
        body += `</div>`;
    });

    body += `</div>
        <div class="modal-total">Total: ₡${parseFloat(o.total).toLocaleString('es-CR')}</div>`;

    document.getElementById('modal_body').innerHTML = body;

    // Botones
    let btns = '';
    if(!['en_cocina', 'listo'].includes(o.estado)) {
        btns += `<button class="btn btn-cocina" onclick="enviarACocina(${o.id})">📤 Cocina</button>`;
    }
    if(!o.esta_pagada) {
        btns += `<button class="btn btn-pagar" onclick="irAPagar(${o.id})">💳 Pagar</button>`;
    } else {
        btns += `<button class="btn btn-completar" onclick="completarOrden(${o.id})">✓ Completar</button>`;
    }
    btns += `<button class="btn btn-ver" onclick="editarOrden(${o.id})">✎ Editar</button>`;
    btns += `<button class="btn btn-eliminar" onclick="eliminarOrden(${o.id})">🗑️ Eliminar</button>`;

    document.getElementById('modal_btns').innerHTML = btns;
    document.getElementById('modal_orden').style.display = 'block';
}

function cerrarModalOrden() {
    document.getElementById('modal_orden').style.display = 'none';
    ordenModalActual = null;
}

// ── ACCIONES ──
function irAPagar(id) {
    window.location.href = 'pago.php?orden_id=' + id;
}

function completarOrden(id) {
    if(confirm('¿Completar esta orden? Se archivará en el historial.')) {
        window.location.href = 'completar_orden.php?orden_id=' + id;
    }
}

function editarOrden(id) {
    window.location.href = 'editar_orden.php?orden_id=' + id;
}

function eliminarOrden(id) {
    const o = ordersData.find(x => x.id === id);
    if(!o) return;

    if(o.detalles.length === 0) {
        // Sin productos: eliminar directo sin pedir motivo
        if(!confirm('¿Eliminar esta orden vacía?')) return;
        fetch('eliminar_orden.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({orden_id: id, motivo: ''})
        }).then(r=>r.json()).then(d => {
            if(d.success) location.reload();
            else alert('Error: ' + d.error);
        });
    } else {
        // Con productos: pedir motivo
        document.getElementById('elimModal_id').value = id;
        document.getElementById('elimModal_orden').textContent = 'Orden #' + o.numero_orden;
        document.getElementById('elimModal_motivo').value = '';
        document.getElementById('elimModal_err').style.display = 'none';
        document.getElementById('modal_eliminar').style.display = 'flex';
    }
}

function confirmarEliminar() {
    const id     = parseInt(document.getElementById('elimModal_id').value);
    const motivo = document.getElementById('elimModal_motivo').value.trim();
    const err    = document.getElementById('elimModal_err');
    if(!motivo) { err.style.display = 'block'; return; }
    err.style.display = 'none';
    fetch('eliminar_orden.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({orden_id: id, motivo})
    }).then(r=>r.json()).then(d => {
        if(d.success) { location.reload(); }
        else alert('Error: ' + d.error);
    });
}

function enviarACocina(id) {
    fetch('enviar_cocina.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({orden_id: id})
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) { location.reload(); }
        else { alert('Error: ' + data.error); }
    });
}

// Recargar cada 30s
setInterval(() => location.reload(), 30000);

// ── CALZONE RÁPIDO ──
function calzoneRapido() {
    document.getElementById('modal_calzone_rapido').style.display = 'block';
    document.getElementById('calzone_cantidad').value = 1;
    actualizarTotalCalzone();
}
function cerrarModalCalzone() {
    document.getElementById('modal_calzone_rapido').style.display = 'none';
}
function cambiarCantidadCalzone(delta) {
    const input = document.getElementById('calzone_cantidad');
    input.value = Math.max(1, (parseInt(input.value) || 1) + delta);
    actualizarTotalCalzone();
}
function actualizarTotalCalzone() {
    const cantidad = parseInt(document.getElementById('calzone_cantidad').value) || 1;
    document.getElementById('calzone_total').textContent = 'Total: ₡' + (cantidad * 1000).toLocaleString('es-CR');
}
function confirmarCalzoneRapido() {
    const cantidad = parseInt(document.getElementById('calzone_cantidad').value) || 1;
    fetch('calzone_rapido.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({cantidad})
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            cerrarModalCalzone();
            window.location.href = 'pago.php?orden_id=' + data.orden_id;
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function abrirArqueo() {
    document.getElementById('modal_arqueo').style.display = 'flex';
    document.getElementById('arqueo_pass').value = '';
    document.getElementById('arqueo_error').textContent = '';
    setTimeout(() => document.getElementById('arqueo_pass').focus(), 100);
}

function cerrarArqueo() {
    document.getElementById('modal_arqueo').style.display = 'none';
}

function verificarArqueo() {
    const pass = document.getElementById('arqueo_pass').value;
    const err  = document.getElementById('arqueo_error');
    if(!pass) { err.textContent = 'Ingresá la contraseña'; return; }

    fetch('verificar_arqueo.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ password: pass })
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            window.location.href = 'arqueo_caja.php';
        } else {
            err.textContent = data.error || 'Contraseña incorrecta';
            document.getElementById('arqueo_pass').value = '';
            document.getElementById('arqueo_pass').focus();
        }
    })
    .catch(() => { err.textContent = 'Error de conexión'; });
}

document.addEventListener('keydown', function(e) {
    if(document.getElementById('modal_arqueo').style.display === 'flex' && e.key === 'Enter') {
        verificarArqueo();
    }
});
</script>

<!-- Modal arqueo de caja -->
<div id="modal_arqueo" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:12px;padding:24px;width:90%;max-width:320px;text-align:center;">
        <div style="font-size:36px;margin-bottom:8px;">💰</div>
        <h2 style="color:#ff9800;font-size:18px;margin-bottom:6px;">Arqueo de Caja</h2>
        <p style="color:#666;font-size:13px;margin-bottom:16px;">Ingresá la contraseña de la administración para continuar.</p>
        <input type="password" id="arqueo_pass" placeholder="Contraseña"
            style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:18px;text-align:center;margin-bottom:10px;letter-spacing:4px;">
        <div id="arqueo_error" style="color:#e53935;font-size:13px;min-height:18px;margin-bottom:10px;"></div>
        <button onclick="verificarArqueo()" style="width:100%;padding:12px;background:#ff9800;color:white;border:none;border-radius:8px;font-size:15px;font-weight:bold;cursor:pointer;margin-bottom:8px;">
            Entrar
        </button>
        <button onclick="cerrarArqueo()" style="width:100%;padding:10px;background:#eee;color:#555;border:none;border-radius:8px;font-size:14px;cursor:pointer;">
            Cancelar
        </button>
    </div>
</div>

</body>
</html>
