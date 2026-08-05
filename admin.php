<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: index.php'); exit;
}
header('Location: erp/index.php'); exit;

require_once 'config.php';

try {
    $conn = getConnection();

    // ── RESUMEN DEL DÍA ──
    $stmt = $conn->query("
        SELECT COUNT(*) as total_ordenes, COALESCE(SUM(total), 0) as total_ventas
        FROM ordenes
        WHERE estado = 'completado' AND DATE(fecha_creacion) = CURDATE()
    ");
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->query("
        SELECT p.metodo_pago, COALESCE(SUM(p.monto_aplicado), 0) as total
        FROM pagos p
        JOIN ordenes o ON p.orden_id = o.id
        WHERE o.estado = 'completado' AND DATE(o.fecha_creacion) = CURDATE()
        GROUP BY p.metodo_pago
    ");
    $pagos_dia = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagos_metodo = ['efectivo' => 0, 'sinpe' => 0, 'tarjeta' => 0];
    foreach($pagos_dia as $p) $pagos_metodo[$p['metodo_pago']] = $p['total'];

    // ── HISTORIAL ──
    $stmt = $conn->query("
        SELECT o.id, o.numero_orden, o.nombre_cliente, o.total, o.fecha_creacion,
               (SELECT COUNT(*) FROM detalle_orden WHERE orden_id = o.id) as num_items
        FROM ordenes o
        WHERE o.estado = 'completado'
        ORDER BY o.fecha_creacion DESC
        LIMIT 200
    ");
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── PRODUCTOS ──
    $stmt = $conn->query("
        SELECT p.*, c.nombre as cat_nombre
        FROM productos p
        JOIN categorias c ON p.categoria_id = c.id
        ORDER BY c.orden ASC, p.nombre ASC
    ");
    $productos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $productos_por_cat = [];
    foreach($productos_raw as $p) {
        $productos_por_cat[$p['cat_nombre']][] = $p;
    }

    // ── USUARIOS ──
    $stmt = $conn->query("SELECT id, nombre, usuario, rol FROM usuarios ORDER BY rol, nombre");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── REPORTES: ventas diarias últimos 30 días ──
    $ventas_diarias = [];
    for($i = 29; $i >= 0; $i--) {
        $fecha = date('Y-m-d', strtotime("-$i days"));
        $ventas_diarias[$fecha] = ['dia' => $fecha, 'total' => 0, 'ordenes' => 0];
    }
    $stmt = $conn->query("
        SELECT DATE(fecha_creacion) as dia, COUNT(*) as ordenes, COALESCE(SUM(total),0) as total
        FROM ordenes
        WHERE estado='completado' AND fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        GROUP BY DATE(fecha_creacion)
    ");
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r)
        if(isset($ventas_diarias[$r['dia']])) $ventas_diarias[$r['dia']] = $r;
    $ventas_diarias = array_values($ventas_diarias);

    // ── REPORTES: top productos ──
    $stmt = $conn->query("
        SELECT d.producto_nombre as nombre, SUM(d.cantidad) as total
        FROM detalle_orden d JOIN ordenes o ON d.orden_id=o.id
        WHERE o.estado='completado' AND o.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY d.producto_nombre ORDER BY total DESC LIMIT 10
    ");
    $top_productos_30 = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->query("
        SELECT d.producto_nombre as nombre, SUM(d.cantidad) as total
        FROM detalle_orden d JOIN ordenes o ON d.orden_id=o.id
        WHERE o.estado='completado' AND o.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY d.producto_nombre ORDER BY total DESC LIMIT 10
    ");
    $top_productos_7 = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── REPORTES: métodos de pago ──
    $metodos_30 = ['efectivo'=>0,'sinpe'=>0,'tarjeta'=>0];
    $stmt = $conn->query("
        SELECT p.metodo_pago, COALESCE(SUM(p.monto_aplicado),0) as total
        FROM pagos p JOIN ordenes o ON p.orden_id=o.id
        WHERE o.estado='completado' AND o.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY p.metodo_pago
    ");
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $metodos_30[$r['metodo_pago']] = (float)$r['total'];

    $metodos_7 = ['efectivo'=>0,'sinpe'=>0,'tarjeta'=>0];
    $stmt = $conn->query("
        SELECT p.metodo_pago, COALESCE(SUM(p.monto_aplicado),0) as total
        FROM pagos p JOIN ordenes o ON p.orden_id=o.id
        WHERE o.estado='completado' AND o.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY p.metodo_pago
    ");
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $metodos_7[$r['metodo_pago']] = (float)$r['total'];

    // ── REPORTES: horas pico ──
    $horas_30 = array_fill(0, 24, 0);
    $stmt = $conn->query("
        SELECT HOUR(fecha_creacion) as hora, COUNT(*) as total
        FROM ordenes WHERE estado='completado' AND fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY HOUR(fecha_creacion)
    ");
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $horas_30[(int)$r['hora']] = (int)$r['total'];

    $horas_7 = array_fill(0, 24, 0);
    $stmt = $conn->query("
        SELECT HOUR(fecha_creacion) as hora, COUNT(*) as total
        FROM ordenes WHERE estado='completado' AND fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY HOUR(fecha_creacion)
    ");
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $horas_7[(int)$r['hora']] = (int)$r['total'];

} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Pizza Yaja</title>
    <script src="notificacion.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #1a1a1a; padding: 12px; }

        .header {
            background: white; padding: 12px 15px; border-radius: 10px;
            margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;
        }
        .header h1 { color: #ff9800; font-size: 20px; }
        .logout-btn {
            background: #c62828; color: white; padding: 8px 14px;
            border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold;
        }

        /* TABS */
        .tabs {
            display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap;
        }
        .tab-btn {
            flex: 1; padding: 10px 6px; border: none; border-radius: 8px;
            font-size: 13px; font-weight: bold; cursor: pointer;
            background: #444; color: #ccc; transition: all 0.2s;
        }
        .tab-btn.active { background: #ff9800; color: white; }

        /* SECCIONES */
        .seccion { display: none; }
        .seccion.activa { display: block; }

        /* CARDS DE RESUMEN */
        .resumen-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px; margin-bottom: 15px;
        }
        .resumen-card {
            background: white; border-radius: 10px; padding: 15px; text-align: center;
        }
        .resumen-card .valor { font-size: 26px; font-weight: bold; color: #ff9800; }
        .resumen-card .etiqueta { font-size: 12px; color: #666; margin-top: 4px; }

        .pago-card {
            background: white; border-radius: 10px; padding: 12px 15px;
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 8px;
        }
        .pago-card .metodo { font-weight: bold; font-size: 15px; }
        .pago-card .monto { font-size: 18px; font-weight: bold; color: #ff9800; }

        /* HISTORIAL */
        .historial-item {
            background: white; border-radius: 8px; padding: 10px 12px;
            margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center;
        }
        .hist-num { font-weight: bold; color: #ff9800; font-size: 14px; }
        .hist-cliente { font-size: 12px; color: #666; }
        .hist-fecha { font-size: 11px; color: #999; }
        .hist-total { font-weight: bold; font-size: 15px; color: #333; }
        .hist-items { font-size: 11px; color: #999; }

        /* PRODUCTOS */
        .cat-titulo {
            color: #ff9800; font-weight: bold; font-size: 15px;
            margin: 15px 0 8px; padding-left: 4px;
        }
        .producto-row {
            background: white; border-radius: 8px; padding: 10px 12px;
            margin-bottom: 6px; display: flex; align-items: center; gap: 10px;
        }
        .prod-nombre { flex: 1; font-size: 14px; font-weight: bold; }
        .prod-precio-input {
            width: 90px; padding: 6px 8px; border: 2px solid #ddd;
            border-radius: 5px; font-size: 14px; text-align: center;
        }
        .prod-precio-input:focus { border-color: #ff9800; outline: none; }
        .btn-guardar-precio {
            padding: 6px 10px; background: #ff9800; color: white;
            border: none; border-radius: 5px; font-size: 12px; font-weight: bold; cursor: pointer;
        }
        .toggle-activo {
            width: 44px; height: 24px; border-radius: 12px; border: none;
            cursor: pointer; font-size: 11px; font-weight: bold; flex-shrink: 0;
            transition: background 0.2s;
        }
        .toggle-activo.on  { background: #4caf50; color: white; }
        .toggle-activo.off { background: #ccc;    color: #666;  }

        /* USUARIOS */
        .usuario-row {
            background: white; border-radius: 8px; padding: 10px 12px;
            margin-bottom: 6px; display: flex; align-items: center; gap: 10px;
        }
        .usr-info { flex: 1; }
        .usr-nombre { font-weight: bold; font-size: 14px; }
        .usr-detalle { font-size: 12px; color: #666; }
        .rol-badge {
            padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;
        }
        .rol-admin    { background: #c62828; color: white; }
        .rol-camarero { background: #2196f3; color: white; }
        .rol-cocina   { background: #9c27b0; color: white; }

        .btn-eliminar-usr {
            background: #c62828; color: white; border: none;
            padding: 6px 10px; border-radius: 5px; font-size: 12px; cursor: pointer;
        }

        /* FORM NUEVO USUARIO */
        .form-nuevo-usr {
            background: white; border-radius: 10px; padding: 15px; margin-top: 15px;
        }
        .form-nuevo-usr h3 { color: #ff9800; margin-bottom: 12px; font-size: 16px; }
        .form-field { margin-bottom: 10px; }
        .form-field label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 4px; }
        .form-field input, .form-field select {
            width: 100%; padding: 9px 10px; border: 2px solid #ddd;
            border-radius: 5px; font-size: 14px;
        }
        .btn-crear-usr {
            width: 100%; padding: 11px; background: #4caf50; color: white;
            border: none; border-radius: 5px; font-size: 15px; font-weight: bold; cursor: pointer;
            margin-top: 5px;
        }

        .section-title {
            color: white; font-size: 16px; font-weight: bold; margin-bottom: 10px;
        }

        .filtro-fecha {
            background: white; border-radius: 8px; padding: 10px 12px;
            margin-bottom: 10px; display: flex; align-items: center; gap: 10px;
        }
        .filtro-fecha label { font-size: 13px; font-weight: bold; }
        .filtro-fecha input { padding: 6px 10px; border: 2px solid #ddd; border-radius: 5px; font-size: 14px; }

        /* REPORTES */
        .rpt-filtros { display: flex; gap: 8px; margin-bottom: 14px; }
        .rpt-btn {
            padding: 8px 20px; border: none; border-radius: 20px;
            font-size: 13px; font-weight: bold; cursor: pointer;
            background: #444; color: #ccc; transition: all 0.2s;
        }
        .rpt-btn.active { background: #ff9800; color: white; }

        .rpt-stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px; margin-bottom: 14px;
        }
        .rpt-stat { background: white; border-radius: 10px; padding: 14px; text-align: center; }
        .rpt-stat .val { font-size: 20px; font-weight: bold; color: #ff9800; }
        .rpt-stat .lbl { font-size: 11px; color: #666; margin-top: 3px; }

        .rpt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .rpt-card { background: white; border-radius: 10px; padding: 14px; }
        .rpt-card.wide { grid-column: 1 / -1; }
        .rpt-card-title {
            font-size: 13px; font-weight: bold; color: #333;
            margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #f5f5f5;
        }
        .rpt-wrap { position: relative; height: 200px; }
        .rpt-wrap.tall { height: 300px; }

        @media (max-width: 540px) {
            .rpt-grid { grid-template-columns: 1fr; }
            .rpt-card.wide { grid-column: 1; }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>⚙️ Panel Admin</h1>
    <a href="logout.php" class="logout-btn">Salir</a>
</div>

<!-- TABS -->
<div class="tabs">
    <button class="tab-btn active" onclick="showTab('resumen')">📊 Resumen</button>
    <button class="tab-btn" onclick="showTab('historial')">📋 Historial</button>
    <button class="tab-btn" onclick="showTab('productos')">🍕 Productos</button>
    <button class="tab-btn" onclick="showTab('usuarios')">👥 Usuarios</button>
    <button class="tab-btn" onclick="showTab('reportes')">📈 Reportes</button>
</div>

<!-- ══════════════════════════════════════════ -->
<!-- SECCIÓN: RESUMEN DEL DÍA                  -->
<!-- ══════════════════════════════════════════ -->
<div class="seccion activa" id="tab-resumen">
    <p class="section-title">Resumen de hoy — <?php echo date('d/m/Y'); ?></p>

    <div class="resumen-grid">
        <div class="resumen-card">
            <div class="valor">₡<?php echo number_format($resumen['total_ventas'], 0); ?></div>
            <div class="etiqueta">Total vendido</div>
        </div>
        <div class="resumen-card">
            <div class="valor"><?php echo $resumen['total_ordenes']; ?></div>
            <div class="etiqueta">Órdenes completadas</div>
        </div>
    </div>

    <p class="section-title">Desglose por método de pago</p>

    <div class="pago-card">
        <div class="metodo">💵 Efectivo</div>
        <div class="monto">₡<?php echo number_format($pagos_metodo['efectivo'], 0); ?></div>
    </div>
    <div class="pago-card">
        <div class="metodo">📌 SINPE</div>
        <div class="monto">₡<?php echo number_format($pagos_metodo['sinpe'], 0); ?></div>
    </div>
    <div class="pago-card">
        <div class="metodo">💳 Tarjeta</div>
        <div class="monto">₡<?php echo number_format($pagos_metodo['tarjeta'], 0); ?></div>
    </div>
</div>

<!-- ══════════════════════════════════════════ -->
<!-- SECCIÓN: HISTORIAL                         -->
<!-- ══════════════════════════════════════════ -->
<div class="seccion" id="tab-historial">
    <div class="filtro-fecha">
        <label>Filtrar por fecha:</label>
        <input type="date" id="filtro_fecha" value="<?php echo date('Y-m-d'); ?>" onchange="filtrarHistorial()">
    </div>

    <div id="historial_lista">
        <?php foreach($historial as $h): ?>
            <?php
            $fecha = date('Y-m-d', strtotime($h['fecha_creacion']));
            $hora  = date('H:i', strtotime($h['fecha_creacion']));
            $cliente = ($h['nombre_cliente'] && !in_array($h['nombre_cliente'], ['Sin asignar', 'Orden Automática']))
                ? htmlspecialchars($h['nombre_cliente']) : 'Sin asignar';
            ?>
            <div class="historial-item" data-fecha="<?php echo $fecha; ?>">
                <div>
                    <div class="hist-num">Orden #<?php echo $h['numero_orden']; ?></div>
                    <div class="hist-cliente"><?php echo $cliente; ?></div>
                    <div class="hist-fecha"><?php echo $hora; ?> — <?php echo date('d/m/Y', strtotime($h['fecha_creacion'])); ?></div>
                </div>
                <div style="text-align:right;">
                    <div class="hist-total">₡<?php echo number_format($h['total'], 0); ?></div>
                    <div class="hist-items"><?php echo $h['num_items']; ?> producto(s)</div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if(empty($historial)): ?>
            <p style="color:white; text-align:center; padding:30px;">No hay órdenes completadas aún</p>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════ -->
<!-- SECCIÓN: PRODUCTOS                         -->
<!-- ══════════════════════════════════════════ -->
<div class="seccion" id="tab-productos">
    <?php foreach($productos_por_cat as $cat => $prods): ?>
        <div class="cat-titulo"><?php echo htmlspecialchars($cat); ?></div>
        <?php foreach($prods as $p): ?>
            <div class="producto-row">
                <div class="prod-nombre"><?php echo htmlspecialchars($p['nombre']); ?></div>
                <input type="number" class="prod-precio-input"
                       id="precio_<?php echo $p['id']; ?>"
                       value="<?php echo (int)$p['precio']; ?>"
                       min="0">
                <button class="btn-guardar-precio"
                        onclick="guardarPrecio(<?php echo $p['id']; ?>)">✓</button>
                <button class="toggle-activo <?php echo $p['activo'] ? 'on' : 'off'; ?>"
                        id="toggle_<?php echo $p['id']; ?>"
                        onclick="toggleActivo(<?php echo $p['id']; ?>)">
                    <?php echo $p['activo'] ? 'ON' : 'OFF'; ?>
                </button>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════ -->
<!-- SECCIÓN: USUARIOS                          -->
<!-- ══════════════════════════════════════════ -->
<div class="seccion" id="tab-usuarios">
    <p class="section-title">Usuarios del sistema</p>

    <?php foreach($usuarios as $u): ?>
        <div class="usuario-row">
            <div class="usr-info">
                <div class="usr-nombre"><?php echo htmlspecialchars($u['nombre']); ?></div>
                <div class="usr-detalle">@<?php echo htmlspecialchars($u['usuario']); ?></div>
            </div>
            <span class="rol-badge rol-<?php echo $u['rol']; ?>"><?php echo ucfirst($u['rol']); ?></span>
            <?php if($u['id'] != $_SESSION['usuario_id']): ?>
                <button class="btn-eliminar-usr" onclick="eliminarUsuario(<?php echo $u['id']; ?>, '<?php echo addslashes($u['nombre']); ?>')">🗑️</button>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="form-nuevo-usr">
        <h3>+ Nuevo Usuario</h3>
        <div class="form-field">
            <label>Nombre completo</label>
            <input type="text" id="usr_nombre" placeholder="Ej: María López">
        </div>
        <div class="form-field">
            <label>Usuario (para login)</label>
            <input type="text" id="usr_usuario" placeholder="Ej: maria">
        </div>
        <div class="form-field">
            <label>Contraseña</label>
            <input type="password" id="usr_password" placeholder="Contraseña">
        </div>
        <div class="form-field">
            <label>Rol</label>
            <select id="usr_rol">
                <option value="camarero">Camarero</option>
                <option value="cocina">Cocina</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <button class="btn-crear-usr" onclick="crearUsuario()">Crear Usuario</button>
    </div>
</div>

<!-- ══════════════════════════════════════════ -->
<!-- SECCIÓN: REPORTES                          -->
<!-- ══════════════════════════════════════════ -->
<div class="seccion" id="tab-reportes">
    <div class="rpt-filtros">
        <button class="rpt-btn active" id="btn7"  onclick="setPeriodo(7)">Últimos 7 días</button>
        <button class="rpt-btn"        id="btn30" onclick="setPeriodo(30)">Últimos 30 días</button>
    </div>

    <div class="rpt-stats" id="rpt-stats"></div>

    <div class="rpt-grid">
        <div class="rpt-card wide">
            <div class="rpt-card-title">📈 Ventas por día</div>
            <div class="rpt-wrap"><canvas id="chartVentas"></canvas></div>
        </div>
        <div class="rpt-card">
            <div class="rpt-card-title">💳 Método de pago</div>
            <div class="rpt-wrap"><canvas id="chartPagos"></canvas></div>
        </div>
        <div class="rpt-card">
            <div class="rpt-card-title">🕐 Horas pico</div>
            <div class="rpt-wrap"><canvas id="chartHoras"></canvas></div>
        </div>
        <div class="rpt-card wide">
            <div class="rpt-card-title">🍕 Productos más vendidos</div>
            <div class="rpt-wrap tall"><canvas id="chartProductos"></canvas></div>
        </div>
    </div>
</div>

<script>
// ── TABS ──
let reportesInited = false;
function showTab(tab) {
    document.querySelectorAll('.seccion').forEach(s => s.classList.remove('activa'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('activa');
    event.target.classList.add('active');
    if(tab === 'reportes' && !reportesInited) {
        reportesInited = true;
        renderReportes();
    }
}

// ── HISTORIAL: FILTRO POR FECHA ──
function filtrarHistorial() {
    const fecha = document.getElementById('filtro_fecha').value;
    document.querySelectorAll('.historial-item').forEach(item => {
        item.style.display = (!fecha || item.dataset.fecha === fecha) ? '' : 'none';
    });
}
filtrarHistorial();

// ── PRODUCTOS: GUARDAR PRECIO ──
function guardarPrecio(id) {
    const precio = parseInt(document.getElementById('precio_' + id).value);
    if(isNaN(precio) || precio < 0) { mostrarNotificacion('Precio inválido', 'error'); return; }

    fetch('admin_producto.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'precio', id, precio})
    })
    .then(r => r.json())
    .then(d => {
        if(d.success) mostrarNotificacion('Precio actualizado');
        else mostrarNotificacion('Error: ' + d.error, 'error');
    });
}

// ── PRODUCTOS: TOGGLE ACTIVO ──
function toggleActivo(id) {
    fetch('admin_producto.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'toggle', id})
    })
    .then(r => r.json())
    .then(d => {
        if(d.success) {
            const btn = document.getElementById('toggle_' + id);
            const activo = d.activo;
            btn.textContent = activo ? 'ON' : 'OFF';
            btn.className = 'toggle-activo ' + (activo ? 'on' : 'off');
            mostrarNotificacion(activo ? 'Producto activado' : 'Producto desactivado');
        } else {
            mostrarNotificacion('Error: ' + d.error, 'error');
        }
    });
}

// ── USUARIOS: ELIMINAR ──
function eliminarUsuario(id, nombre) {
    if(!confirm('¿Eliminar al usuario "' + nombre + '"?')) return;
    fetch('admin_usuario.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'eliminar', id})
    })
    .then(r => r.json())
    .then(d => {
        if(d.success) location.reload();
        else mostrarNotificacion('Error: ' + d.error, 'error');
    });
}

// ── REPORTES ──
const RPT = {
    ventas:      <?php echo json_encode($ventas_diarias); ?>,
    prod7:       <?php echo json_encode($top_productos_7); ?>,
    prod30:      <?php echo json_encode($top_productos_30); ?>,
    metodos7:    <?php echo json_encode($metodos_7); ?>,
    metodos30:   <?php echo json_encode($metodos_30); ?>,
    horas7:      <?php echo json_encode(array_values($horas_7)); ?>,
    horas30:     <?php echo json_encode(array_values($horas_30)); ?>,
};

let rptPeriodo = 7;
const rptCharts = {};

function setPeriodo(p) {
    rptPeriodo = p;
    document.getElementById('btn7').classList.toggle('active', p === 7);
    document.getElementById('btn30').classList.toggle('active', p === 30);
    renderReportes();
}

function renderReportes() {
    const ventas = rptPeriodo === 7 ? RPT.ventas.slice(-7) : RPT.ventas;
    const totalVentas  = ventas.reduce((s, d) => s + parseFloat(d.total), 0);
    const totalOrdenes = ventas.reduce((s, d) => s + parseInt(d.ordenes), 0);
    const ticket = totalOrdenes > 0 ? totalVentas / totalOrdenes : 0;

    document.getElementById('rpt-stats').innerHTML =
        `<div class="rpt-stat"><div class="val">₡${nf(totalVentas)}</div><div class="lbl">Total vendido</div></div>
         <div class="rpt-stat"><div class="val">${totalOrdenes}</div><div class="lbl">Órdenes</div></div>
         <div class="rpt-stat"><div class="val">₡${nf(ticket)}</div><div class="lbl">Ticket promedio</div></div>`;

    // Ventas por día
    makeBar('chartVentas',
        ventas.map(d => { const p = d.dia.split('-'); return p[2]+'/'+p[1]; }),
        ventas.map(d => parseFloat(d.total)),
        'rgba(255,152,0,0.85)', 'rgba(255,152,0,1)'
    );

    // Método de pago (dona)
    const m = rptPeriodo === 7 ? RPT.metodos7 : RPT.metodos30;
    makeDona('chartPagos',
        ['Efectivo','SINPE','Tarjeta'],
        [m.efectivo, m.sinpe, m.tarjeta],
        ['rgba(76,175,80,0.85)','rgba(255,152,0,0.85)','rgba(33,150,243,0.85)']
    );

    // Horas pico
    const horas = rptPeriodo === 7 ? RPT.horas7 : RPT.horas30;
    makeBar('chartHoras',
        Array.from({length:24}, (_,i) => i+':00'),
        horas,
        'rgba(156,39,176,0.75)', 'rgba(156,39,176,1)'
    );

    // Productos más vendidos (horizontal)
    const prods = rptPeriodo === 7 ? RPT.prod7 : RPT.prod30;
    makeHBar('chartProductos',
        prods.map(p => p.nombre),
        prods.map(p => parseInt(p.total))
    );
}

function makeBar(id, labels, data, bg, border) {
    if(rptCharts[id]) rptCharts[id].destroy();
    rptCharts[id] = new Chart(document.getElementById(id), {
        type: 'bar',
        data: { labels, datasets: [{ data, backgroundColor: bg, borderColor: border, borderWidth: 1, borderRadius: 4 }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' } },
                x: { grid: { display: false }, ticks: { font: { size: 11 } } }
            }
        }
    });
}

function makeDona(id, labels, data, colors) {
    if(rptCharts[id]) rptCharts[id].destroy();
    rptCharts[id] = new Chart(document.getElementById(id), {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 12 } } },
                tooltip: { callbacks: { label: ctx => ' ₡' + nf(ctx.raw) } }
            }
        }
    });
}

function makeHBar(id, labels, data) {
    if(rptCharts[id]) rptCharts[id].destroy();
    rptCharts[id] = new Chart(document.getElementById(id), {
        type: 'bar',
        data: { labels, datasets: [{ data, backgroundColor: 'rgba(255,107,0,0.75)', borderColor: 'rgba(255,107,0,1)', borderWidth: 1, borderRadius: 4 }] },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' } },
                y: { grid: { display: false }, ticks: { font: { size: 11 } } }
            }
        }
    });
}

function nf(n) { return Math.round(n).toLocaleString('es-CR'); }

// ── USUARIOS: CREAR ──
function crearUsuario() {
    const nombre   = document.getElementById('usr_nombre').value.trim();
    const usuario  = document.getElementById('usr_usuario').value.trim();
    const password = document.getElementById('usr_password').value;
    const rol      = document.getElementById('usr_rol').value;

    if(!nombre || !usuario || !password) {
        mostrarNotificacion('Completá todos los campos', 'error'); return;
    }

    fetch('admin_usuario.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'crear', nombre, usuario, contrasena: password, rol})
    })
    .then(r => r.json())
    .then(d => {
        if(d.success) {
            mostrarNotificacion('Usuario creado');
            setTimeout(() => location.reload(), 800);
        } else {
            mostrarNotificacion('Error: ' + d.error, 'error');
        }
    });
}
</script>
</body>
</html>
