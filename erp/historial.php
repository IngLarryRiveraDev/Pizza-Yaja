<?php
session_start();
if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: ../index.php'); exit;
}
require_once '../config.php';
$conn = getConnection();

$conn->exec("
    CREATE TABLE IF NOT EXISTS log_eliminaciones (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        orden_id       INT,
        numero_orden   INT,
        nombre_cliente VARCHAR(200),
        total          DECIMAL(10,2),
        motivo         TEXT NOT NULL,
        eliminado_por  VARCHAR(100),
        sucursal       VARCHAR(20) NULL,
        fecha          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
try { $conn->exec("ALTER TABLE log_eliminaciones ADD COLUMN sucursal VARCHAR(20) NULL"); } catch(PDOException $e) {}

// AJAX: eliminar registro del log
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    if(($d['accion'] ?? '') === 'eliminar_log') {
        try {
            $conn->prepare("DELETE FROM log_eliminaciones WHERE id=?")->execute([(int)$d['id']]);
            echo json_encode(['success'=>true]);
        } catch(PDOException $e) {
            echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        }
    } else {
        echo json_encode(['success'=>false,'error'=>'Acción desconocida']);
    }
    exit;
}

if(isset($_GET['suc']) && in_array($_GET['suc'], ['cariari','guapiles','ambas'])) {
    $_SESSION['erp_sucursal'] = $_GET['suc'];
}
$suc_filtro = $_SESSION['erp_sucursal'] ?? 'ambas';

// Día seleccionado
$hoy   = date('Y-m-d');
$fecha = $_GET['fecha'] ?? $hoy;
$dt    = DateTime::createFromFormat('Y-m-d', $fecha);
if(!$dt || $dt->format('Y-m-d') !== $fecha) $fecha = $hoy;
if($fecha > $hoy) $fecha = $hoy;
$diaAnt = date('Y-m-d', strtotime($fecha . ' -1 day'));
$diaSig = date('Y-m-d', strtotime($fecha . ' +1 day'));
$esHoy  = $fecha === $hoy;

$tab  = ($_GET['tab'] ?? '') === 'eliminadas' ? 'eliminadas' : 'completadas';
$busq = trim($_GET['q'] ?? '');

$dias   = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses  = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$ts     = strtotime($fecha);
$fechaTexto = $dias[date('w',$ts)] . ' ' . date('j',$ts) . ' de ' . $meses[date('n',$ts)-1] . ' ' . date('Y',$ts);

$sucLabels = ['cariari'=>'Cariari','guapiles'=>'Guápiles'];

// ── Órdenes completadas del día
$where  = ["o.estado = 'completado'", "DATE(o.fecha_creacion) = ?"];
$params = [$fecha];
if($suc_filtro !== 'ambas') { $where[] = "o.sucursal = ?"; $params[] = $suc_filtro; }
$whereBase  = implode(' AND ', $where);
$paramsBase = $params;
if($busq !== '') {
    $where[] = "(o.numero_orden LIKE ? OR o.nombre_cliente LIKE ?)";
    $params[] = "%{$busq}%"; $params[] = "%{$busq}%";
}
$whereSQL = implode(' AND ', $where);

$stmt = $conn->prepare("
  SELECT o.id, o.numero_orden, o.nombre_cliente, o.total, o.fecha_creacion, o.sucursal,
         GROUP_CONCAT(DISTINCT p.metodo_pago ORDER BY p.metodo_pago SEPARATOR '/') AS metodo_pago
  FROM ordenes o LEFT JOIN pagos p ON p.orden_id=o.id
  WHERE {$whereSQL}
  GROUP BY o.id ORDER BY o.fecha_creacion ASC
");
$stmt->execute($params);
$ordenes = $stmt->fetchAll();

// Resumen del día (sin el filtro de búsqueda)
$resQ = $conn->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(o.total),0) AS t FROM ordenes o WHERE {$whereBase}");
$resQ->execute($paramsBase);
$res = $resQ->fetch();

$pagQ = $conn->prepare("
  SELECT p.metodo_pago, COALESCE(SUM(p.monto_aplicado),0) AS total
  FROM pagos p JOIN ordenes o ON p.orden_id=o.id
  WHERE {$whereBase}
  GROUP BY p.metodo_pago
");
$pagQ->execute($paramsBase);
$porMetodo = ['efectivo'=>0,'sinpe'=>0,'tarjeta'=>0];
foreach($pagQ->fetchAll() as $r) $porMetodo[$r['metodo_pago']] = (float)$r['total'];

// Todo lo vendido ese día, sin recortar a un top
$vendQ = $conn->prepare("
  SELECT d.producto_nombre AS nombre, SUM(d.cantidad) AS qty,
         SUM(d.precio_unitario * d.cantidad) AS total
  FROM detalle_orden d JOIN ordenes o ON d.orden_id = o.id
  WHERE {$whereBase}
  GROUP BY d.producto_nombre
  ORDER BY qty DESC, d.producto_nombre
");
$vendQ->execute($paramsBase);
$vendidos = $vendQ->fetchAll();
$vendUnidades = array_sum(array_column($vendidos, 'qty'));

// Calzones: los vendidos sueltos ('Calzone ...') y los que vienen dentro de
// un combo, que en la descripción quedan como 'Calzone: sabor'
function contarCalzones($conn, $where, $params) {
    $q = $conn->prepare("
      SELECT
        COALESCE(SUM(CASE WHEN d.producto_nombre LIKE 'Calzone%' THEN d.cantidad ELSE 0 END), 0) AS sueltos,
        COALESCE(SUM(CASE WHEN d.producto_nombre LIKE '%Calzone%'
                           AND d.producto_nombre NOT LIKE 'Calzone%' THEN d.cantidad ELSE 0 END), 0) AS en_combos
      FROM detalle_orden d JOIN ordenes o ON d.orden_id = o.id
      WHERE {$where}
    ");
    $q->execute($params);
    $r = $q->fetch();
    return [(int)($r['sueltos'] ?? 0), (int)($r['en_combos'] ?? 0)];
}

[$calzSueltos, $calzCombos] = contarCalzones($conn, $whereBase, $paramsBase);
$calzTotal = $calzSueltos + $calzCombos;

// Calzones por turno. Los turnos salen de los cierres de caja: cada cierre
// guarda desde y hasta cuándo fue su turno. Las órdenes se cuentan por la hora
// en que se crearon (para cerrar hay que dejar todo resuelto, así que una orden
// siempre cae en el turno donde se creó). Cada sucursal tiene sus propios turnos.
$calzTurnos = [];
try {
    $inicioDia = $fecha . ' 00:00:00';
    $finDia    = date('Y-m-d 00:00:00', strtotime($fecha . ' +1 day'));
    $ahora     = date('Y-m-d H:i:s');
    $sucsTurno = $suc_filtro === 'ambas' ? array_keys($sucLabels) : [$suc_filtro];

    $cierresQ = $conn->prepare("
        SELECT a.periodo_desde, a.periodo_hasta, u.nombre
        FROM arqueos a LEFT JOIN usuarios u ON u.id = a.usuario_id
        WHERE a.sucursal = ? AND a.periodo_desde IS NOT NULL AND a.periodo_hasta IS NOT NULL
          AND a.periodo_hasta > ? AND a.periodo_desde < ?
        ORDER BY a.periodo_hasta
    ");
    // Hasta dónde llegan los cierres que empezaron antes de que termine el día
    // (incluye un turno que cerró pasada la medianoche)
    $ultimoQ = $conn->prepare("SELECT MAX(periodo_hasta) FROM arqueos WHERE sucursal = ? AND periodo_desde < ?");

    foreach($sucsTurno as $s) {
        $segs = [];
        $cierresQ->execute([$s, $inicioDia, $finDia]);
        foreach($cierresQ->fetchAll() as $c) {
            $segs[] = [
                'desde'  => max($c['periodo_desde'], $inicioDia),
                'hasta'  => min($c['periodo_hasta'], $finDia),
                'quien'  => $c['nombre'] ?: '—',
                'estado' => 'cerrado',
            ];
        }

        // Lo que queda después del último cierre: el turno en curso (si es hoy)
        // o un turno que nunca se cerró (días pasados)
        $ultimoQ->execute([$s, $finDia]);
        $desdeResto = max($ultimoQ->fetchColumn() ?: $inicioDia, $inicioDia); // si el último cierre pasó la medianoche, no queda resto
        $hastaResto = $esHoy ? $ahora : $finDia;
        if($desdeResto < $hastaResto) {
            $segs[] = ['desde' => $desdeResto, 'hasta' => $hastaResto, 'quien' => '', 'estado' => $esHoy ? 'en_curso' : 'sin_cierre'];
        }

        foreach($segs as $i => $seg) {
            // El inicio del día se incluye (> 1 segundo antes); los cierres usan "> desde AND <= hasta"
            $desdeSQL = $seg['desde'] === $inicioDia ? date('Y-m-d H:i:s', strtotime($inicioDia) - 1) : $seg['desde'];
            [$su, $co] = contarCalzones($conn,
                "o.estado = 'completado' AND o.sucursal = ? AND o.fecha_creacion > ? AND o.fecha_creacion <= ?",
                [$s, $desdeSQL, $seg['hasta']]);
            $calzTurnos[] = $seg + ['sucursal' => $s, 'num' => $i + 1, 'total' => $su + $co, 'unico' => count($segs) === 1];
        }
    }
} catch(PDOException $e) {
    $calzTurnos = []; // si no existe la tabla de cierres, queda solo el total del día
}
// Solo vale la pena desglosar si el día tuvo más de un turno en alguna sucursal
$turnosPorSuc = array_count_values(array_column($calzTurnos, 'sucursal'));
$mostrarTurnos = !empty($turnosPorSuc) && max($turnosPorSuc) > 1;

// ── Eliminadas del día
$eWhere  = ["DATE(fecha) = ?"];
$eParams = [$fecha];
if($suc_filtro !== 'ambas') { $eWhere[] = "sucursal = ?"; $eParams[] = $suc_filtro; }
$eQ = $conn->prepare("SELECT * FROM log_eliminaciones WHERE " . implode(' AND ', $eWhere) . " ORDER BY fecha ASC");
$eQ->execute($eParams);
$eliminadas = $eQ->fetchAll();

function urlHist($cambios) {
    $p = array_merge(['tab'=>$GLOBALS['tab'], 'fecha'=>$GLOBALS['fecha']], $cambios);
    unset($p['suc']);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Historial · Pizza Yaja ERP</title>
<style>
.dia-nav { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.dia-nav .flecha { padding:8px 14px; font-size:16px; }
.dia-nav .flecha.off { opacity:.35; pointer-events:none; }
.dia-titulo { font-size:18px; font-weight:800; }
.dia-titulo small { display:block; font-size:12px; font-weight:600; color:var(--orange); }
</style>
</head>
<body class="erp">
<?php include 'includes/sidebar.php'; ?>

<div class="erp-main">
  <div class="erp-topbar">
    <button class="erp-hbg" onclick="erpHbg()">☰</button>
    <span class="erp-pg-title">📋 Historial</span>
    <span class="erp-topbar-right"><?= $suc_filtro === 'ambas' ? 'Ambas sucursales' : $sucLabels[$suc_filtro] ?></span>
  </div>

  <div class="erp-content">

    <!-- Navegación por día -->
    <div class="ec" style="margin-bottom:16px">
      <div class="ec-body" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div class="dia-nav">
          <a class="eb gry flecha" href="<?= urlHist(['fecha'=>$diaAnt, 'q'=>'']) ?>" title="Día anterior">←</a>
          <div class="dia-titulo">
            <?= $fechaTexto ?>
            <?php if($esHoy): ?><small>HOY</small><?php endif; ?>
          </div>
          <a class="eb gry flecha <?= $esHoy ? 'off' : '' ?>" href="<?= urlHist(['fecha'=>$diaSig, 'q'=>'']) ?>" title="Día siguiente">→</a>
        </div>
        <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <input type="hidden" name="tab" value="<?= $tab ?>">
          <input class="ef" type="date" name="fecha" value="<?= $fecha ?>" max="<?= $hoy ?>" style="width:160px" onchange="this.form.submit()">
          <?php if(!$esHoy): ?><a class="eb ora" href="<?= urlHist(['fecha'=>$hoy, 'q'=>'']) ?>">Ir a hoy</a><?php endif; ?>
          <button type="button" class="eb grn" onclick="abrirExport()">📥 Exportar a Excel</button>
        </form>
      </div>
    </div>

    <!-- Resumen del día -->
    <div class="kpi-row">
      <div class="kpi ora">
        <div class="kpi-icon">💰</div>
        <div class="kpi-lbl">Total vendido</div>
        <div class="kpi-val" style="font-size:22px">₡<?= number_format($res['t'],0) ?></div>
        <div class="kpi-sub"><?= (int)$res['c'] ?> orden<?= (int)$res['c'] === 1 ? '' : 'es' ?> completada<?= (int)$res['c'] === 1 ? '' : 's' ?></div>
      </div>
      <div class="kpi grn">
        <div class="kpi-icon">💵</div>
        <div class="kpi-lbl">Efectivo</div>
        <div class="kpi-val" style="font-size:22px">₡<?= number_format($porMetodo['efectivo'],0) ?></div>
      </div>
      <div class="kpi blu">
        <div class="kpi-icon">📌</div>
        <div class="kpi-lbl">SINPE</div>
        <div class="kpi-val" style="font-size:22px">₡<?= number_format($porMetodo['sinpe'],0) ?></div>
      </div>
      <div class="kpi pur">
        <div class="kpi-icon">💳</div>
        <div class="kpi-lbl">Tarjeta</div>
        <div class="kpi-val" style="font-size:22px">₡<?= number_format($porMetodo['tarjeta'],0) ?></div>
      </div>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid var(--border)">
      <a href="<?= urlHist(['tab'=>'completadas']) ?>"
         style="padding:8px 18px;font-size:14px;font-weight:700;text-decoration:none;border-bottom:3px solid <?= $tab==='completadas'?'var(--orange)':'transparent' ?>;color:<?= $tab==='completadas'?'var(--orange)':'#888' ?>;margin-bottom:-2px">
        ✅ Completadas (<?= (int)$res['c'] ?>)
      </a>
      <a href="<?= urlHist(['tab'=>'eliminadas', 'q'=>'']) ?>"
         style="padding:8px 18px;font-size:14px;font-weight:700;text-decoration:none;border-bottom:3px solid <?= $tab==='eliminadas'?'var(--red)':'transparent' ?>;color:<?= $tab==='eliminadas'?'var(--red)':'#888' ?>;margin-bottom:-2px">
        🗑️ Eliminadas <?php if(count($eliminadas)>0): ?><span style="background:var(--red);color:#fff;border-radius:50px;padding:1px 7px;font-size:11px;margin-left:4px"><?= count($eliminadas) ?></span><?php endif; ?>
      </a>
    </div>

    <?php if($tab === 'eliminadas'): ?>
    <div class="ec">
      <div class="ec-head" style="color:var(--red)">🗑️ Órdenes eliminadas del día</div>
      <div class="ec-body np">
        <table class="et">
          <thead><tr><th>Hora</th><th># Orden</th><th>Cliente</th><?php if($suc_filtro==='ambas'): ?><th>Sucursal</th><?php endif; ?><th>Total</th><th>Motivo</th><th>Eliminado por</th><th></th></tr></thead>
          <tbody>
          <?php foreach($eliminadas as $e): ?>
            <tr>
              <td style="white-space:nowrap;color:#888;font-size:12px"><?= date('H:i', strtotime($e['fecha'])) ?></td>
              <td><strong>#<?= $e['numero_orden'] ?></strong></td>
              <td><?= htmlspecialchars($e['nombre_cliente'] ?: '—') ?></td>
              <?php if($suc_filtro==='ambas'): ?>
              <td><?= $e['sucursal'] ? '<span class="bdg '.($e['sucursal']==='guapiles'?'bdg-blu':'bdg-org').'">'.($sucLabels[$e['sucursal']] ?? htmlspecialchars($e['sucursal'])).'</span>' : '<span style="color:#ccc">—</span>' ?></td>
              <?php endif; ?>
              <td style="color:var(--red);font-weight:700">₡<?= number_format($e['total'],0) ?></td>
              <td style="max-width:260px">
                <span style="background:#fff3f3;color:#c62828;padding:3px 8px;border-radius:4px;font-size:12px"><?= htmlspecialchars($e['motivo']) ?></span>
              </td>
              <td style="color:#888;font-size:12px"><?= htmlspecialchars($e['eliminado_por'] ?? '—') ?></td>
              <td><button class="eb red" style="padding:4px 10px;font-size:12px" onclick="eliminarLog(<?= $e['id'] ?>, this)">Eliminar</button></td>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($eliminadas)): ?>
            <tr><td colspan="8" style="text-align:center;color:#aaa;padding:30px">No se eliminaron órdenes este día</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php else: ?>

    <!-- Lo que se vendió ese día, producto por producto -->
    <div class="ec">
      <div class="ec-head" style="justify-content:space-between">
        <span>🍕 Productos vendidos</span>
        <span style="font-size:12px;color:#888;font-weight:400">
          <?= count($vendidos) ?> producto<?= count($vendidos) === 1 ? '' : 's' ?> · <?= (int)$vendUnidades ?> unidad<?= (int)$vendUnidades === 1 ? '' : 'es' ?>
        </span>
      </div>
      <div class="ec-body np" style="max-height:360px;overflow-y:auto">
        <table class="et">
          <thead><tr><th>Producto</th><th style="text-align:right">Cant.</th><th style="text-align:right">Total</th></tr></thead>
          <tbody>
          <?php if(empty($vendidos)): ?>
            <tr><td colspan="3" style="text-align:center;color:#aaa;padding:25px">No se vendió nada este día</td></tr>
          <?php else: foreach($vendidos as $v): ?>
            <tr>
              <td><?= htmlspecialchars($v['nombre']) ?></td>
              <td style="text-align:right;font-weight:700"><?= (int)$v['qty'] ?></td>
              <td style="text-align:right">₡<?= number_format($v['total'], 0) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="ec">
      <div class="ec-head" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <span>Órdenes del día</span>
        <span style="display:flex;align-items:center;gap:6px;margin-left:auto;margin-right:8px;
                     background:#fff3e0;border:1px solid #ffcc80;border-radius:8px;padding:5px 10px">
          <span style="font-size:15px">🥟</span>
          <strong style="color:#e65100;font-size:15px"><?= $calzTotal ?></strong>
          <span style="font-size:12px;color:#8d6e63">
            calzone<?= $calzTotal === 1 ? '' : 's' ?>
            <?php if($calzCombos > 0): ?>
              (<?= $calzSueltos ?> suelto<?= $calzSueltos === 1 ? '' : 's' ?> + <?= $calzCombos ?> en combo<?= $calzCombos === 1 ? '' : 's' ?>)
            <?php endif; ?>
          </span>
        </span>
        <form method="get" style="display:flex;gap:6px;margin:0">
          <input type="hidden" name="tab" value="completadas">
          <input type="hidden" name="fecha" value="<?= $fecha ?>">
          <input class="ef" style="width:200px;padding:6px 10px;font-size:13px" name="q" value="<?= htmlspecialchars($busq) ?>" placeholder="Buscar # orden o cliente">
          <?php if($busq !== ''): ?><a class="eb gry" style="padding:6px 10px" href="<?= urlHist(['q'=>'']) ?>">✕</a><?php endif; ?>
        </form>
      </div>
      <?php if($mostrarTurnos): ?>
      <!-- Calzones por turno (cada turno va de un cierre de caja al siguiente) -->
      <div style="display:flex;flex-wrap:wrap;gap:8px;padding:10px 16px;border-bottom:1px solid var(--border);background:#fffaf3">
        <span style="font-size:12px;color:#8d6e63;font-weight:700;align-self:center">🥟 Por turno:</span>
        <?php foreach($calzTurnos as $t):
          $prefijo = $suc_filtro === 'ambas' ? $sucLabels[$t['sucursal']] . ' · ' : '';
          if($t['unico']) {
              // Sucursal con un solo turno ese día (solo pasa viendo "Ambas"): es el día completo
              $etiqueta = $prefijo . 'todo el día';
              $horas = '';
              $detalle = '';
          } else {
              $etiqueta = $prefijo . 'Turno ' . $t['num'];
              // Un turno recortado a la medianoche termina a las 24:00, no a las 00:00
              $fin = $t['estado'] === 'en_curso' ? 'ahora'
                   : ($t['hasta'] === $finDia ? '24:00' : date('H:i', strtotime($t['hasta'])));
              $horas = date('H:i', strtotime($t['desde'])) . '–' . $fin;
              $detalle = $t['estado'] === 'cerrado' ? 'cerró ' . htmlspecialchars($t['quien'])
                       : ($t['estado'] === 'en_curso' ? 'en curso' : 'sin cierre');
          }
          $color = $t['estado'] === 'en_curso' ? '#2e7d32' : ($t['estado'] === 'sin_cierre' ? '#c62828' : '#8d6e63');
        ?>
          <span style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #ffcc80;border-radius:8px;padding:5px 10px;font-size:12px">
            <strong style="color:#e65100;font-size:14px"><?= $t['total'] ?></strong>
            <span style="color:#555"><?= $etiqueta ?> <span style="color:#999"><?= $horas ?></span></span>
            <span style="color:<?= $color ?>"><?= $detalle ?></span>
          </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="ec-body np">
        <table class="et">
          <thead>
            <tr>
              <th>Hora</th>
              <th>N° Orden</th>
              <th>Cliente / Mesa</th>
              <?php if($suc_filtro==='ambas'): ?><th>Sucursal</th><?php endif; ?>
              <th>Método</th>
              <th style="text-align:right">Total</th>
              <th>Detalle</th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($ordenes)): ?>
            <tr><td colspan="7" style="text-align:center;color:#aaa;padding:30px"><?= $busq !== '' ? 'Ninguna orden coincide con la búsqueda' : 'No hubo órdenes completadas este día' ?></td></tr>
          <?php else: foreach($ordenes as $o):
            $m = strtolower($o['metodo_pago'] ?? '');
            $cls = str_contains($m,'/') ? 'bdg-gry' : (str_contains($m,'efec') ? 'bdg-grn' : (str_contains($m,'sinpe') ? 'bdg-blu' : (str_contains($m,'tarj') ? 'bdg-pur' : 'bdg-gry')));
          ?>
            <tr>
              <td style="color:#888;font-size:12px;white-space:nowrap"><?= date('H:i', strtotime($o['fecha_creacion'])) ?></td>
              <td><strong>#<?= $o['numero_orden'] ?></strong></td>
              <td><?= htmlspecialchars($o['nombre_cliente'] ?: '—') ?></td>
              <?php if($suc_filtro==='ambas'): ?>
              <td><span class="bdg <?= $o['sucursal']==='guapiles'?'bdg-blu':'bdg-org' ?>"><?= $sucLabels[$o['sucursal']] ?? htmlspecialchars($o['sucursal'] ?? '—') ?></span></td>
              <?php endif; ?>
              <td><span class="bdg <?= $cls ?>"><?= htmlspecialchars($o['metodo_pago'] ?: '—') ?></span></td>
              <td style="text-align:right;font-weight:700">₡<?= number_format($o['total'],0) ?></td>
              <td><button class="eb gry" style="padding:4px 10px;font-size:12px" onclick="verDetalle(<?= $o['id'] ?>)">Ver</button></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- Modal detalle -->
<div id="mModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:520px;max-height:85vh;display:flex;flex-direction:column">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;font-weight:700;font-size:15px;display:flex;justify-content:space-between">
      <span id="mTitle">Detalle orden</span>
      <button onclick="cerrarModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div id="mBody" style="padding:16px 20px;overflow-y:auto;flex:1;font-size:14px">Cargando...</div>
  </div>
</div>

<!-- Modal exportar a Excel -->
<div id="xModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;width:92%;max-width:440px">
    <div style="padding:16px 20px;border-bottom:1px solid #eee;font-weight:700;font-size:15px;display:flex;justify-content:space-between">
      <span>📥 Exportar historial a Excel</span>
      <button onclick="cerrarExport()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888">×</button>
    </div>
    <div style="padding:20px">
      <div style="display:flex;gap:6px;margin-bottom:14px">
        <button type="button" id="xSem" class="eb ora" style="flex:1" onclick="xVista('semana')">Una semana</button>
        <button type="button" id="xMes" class="eb gry" style="flex:1" onclick="xVista('mes')">Un mes</button>
      </div>
      <div style="display:flex;align-items:center;gap:8px;justify-content:space-between;background:#f7f7f7;border-radius:10px;padding:10px">
        <button type="button" class="eb gry" style="padding:6px 12px" onclick="xMover(-1)">←</button>
        <strong id="xTitulo" style="text-align:center;font-size:14px"></strong>
        <button type="button" class="eb gry" id="xSig" style="padding:6px 12px" onclick="xMover(1)">→</button>
      </div>
      <p style="font-size:12px;color:#888;margin:12px 0 0;line-height:1.6">
        Incluye resumen por día, todas las órdenes con sus productos, lo vendido y las órdenes eliminadas,
        <strong>separado por semana (lunes a domingo)</strong>.
        Sucursal: <strong><?= $suc_filtro === 'ambas' ? 'ambas' : $sucLabels[$suc_filtro] ?></strong>.
      </p>
      <div id="xEstado" style="font-size:13px;margin-top:10px;min-height:18px"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
        <button type="button" class="eb gry" onclick="cerrarExport()">Cancelar</button>
        <button type="button" class="eb grn" id="xBtn" onclick="exportarExcel()">Descargar Excel</button>
      </div>
    </div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

// ══════════════ Exportar a Excel ══════════════
// Las fechas se manejan como texto 'AAAA-MM-DD' y en UTC para que la zona horaria no corra los días.
const HOY_ISO = '<?= $hoy ?>';
const MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
const DIAS_C = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
const iso  = d => d.toISOString().slice(0, 10);
const aUTC = s => new Date(s + 'T00:00:00Z');
const sumarDias = (s, n) => { const d = aUTC(s); d.setUTCDate(d.getUTCDate() + n); return iso(d); };
const lunesDe = s => { const d = aUTC(s); return sumarDias(s, -((d.getUTCDay() + 6) % 7)); };
const fCorta = s => { const d = aUTC(s); return d.getUTCDate() + ' ' + MESES[d.getUTCMonth()].slice(0, 3); };
const fLarga = s => s.split('-').reverse().join('/');

let xEst = { vista: 'semana', ref: '<?= $fecha ?>' };

function xRango() {
  if (xEst.vista === 'semana') {
    const l = lunesDe(xEst.ref);
    return { desde: l, hasta: sumarDias(l, 7),
             titulo: 'Semana del Lun ' + fCorta(l) + ' al Dom ' + fCorta(sumarDias(l, 6)),
             archivo: 'semana ' + fCorta(l) + ' al ' + fCorta(sumarDias(l, 6)) + ' ' + l.slice(0, 4) };
  }
  const d = aUTC(xEst.ref);
  const desde = iso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1)));
  const hasta = iso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 1)));
  const nombre = MESES[d.getUTCMonth()] + ' ' + d.getUTCFullYear();
  return { desde, hasta, titulo: nombre.charAt(0).toUpperCase() + nombre.slice(1), archivo: nombre };
}
function xPintar() {
  const r = xRango();
  document.getElementById('xTitulo').textContent = r.titulo;
  document.getElementById('xSem').className = 'eb ' + (xEst.vista === 'semana' ? 'ora' : 'gry');
  document.getElementById('xMes').className = 'eb ' + (xEst.vista === 'mes' ? 'ora' : 'gry');
  const sig = document.getElementById('xSig');
  const hayFuturo = r.hasta > HOY_ISO;
  sig.disabled = hayFuturo; sig.style.opacity = hayFuturo ? .35 : 1;
  document.getElementById('xEstado').textContent = '';
}
function xVista(v) { xEst.vista = v; xPintar(); }
function xMover(n) {
  const r = xRango();
  if (xEst.vista === 'semana') xEst.ref = sumarDias(r.desde, 7 * n);
  else { const d = aUTC(r.desde); xEst.ref = iso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1))); }
  if (xEst.ref > HOY_ISO) xEst.ref = HOY_ISO;
  xPintar();
}
function abrirExport() { xEst = { vista: 'semana', ref: '<?= $fecha ?>' }; xPintar(); document.getElementById('xModal').style.display = 'flex'; }
function cerrarExport() { document.getElementById('xModal').style.display = 'none'; }

function cargarExcelJS() {
  if (window.ExcelJS) return Promise.resolve();
  return new Promise((ok, mal) => {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js';
    s.onload = ok;
    s.onerror = () => mal(new Error('No se pudo cargar la herramienta de Excel. Revisá la conexión a internet.'));
    document.head.appendChild(s);
  });
}

async function exportarExcel() {
  const r = xRango();
  const btn = document.getElementById('xBtn'), est = document.getElementById('xEstado');
  btn.disabled = true; est.style.color = '#888'; est.textContent = 'Preparando el archivo…';
  try {
    const [datos] = await Promise.all([
      fetch('historial_export.php?desde=' + r.desde + '&hasta=' + r.hasta).then(x => x.json()),
      cargarExcelJS(),
    ]);
    if (!datos.success) throw new Error(datos.error || 'No se pudieron leer los datos');
    const libro = armarLibro(datos, r);
    const buf = await libro.xlsx.writeBuffer();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
    a.download = 'Historial Pizza Yaja - ' + r.archivo + (datos.variasSuc ? '' : ' - ' + datos.sucursal) + '.xlsx';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
    est.style.color = '#2e7d32';
    est.textContent = '✅ Listo: ' + datos.ordenes.length + ' órdenes exportadas.';
  } catch (e) {
    est.style.color = '#c62828'; est.textContent = '⚠️ ' + e.message;
  } finally { btn.disabled = false; }
}

function armarLibro(D, R) {
  const NARANJA = 'FFFF6B00', OSCURO = 'FF2B2B2B', SEMANA = 'FFFFE0B2', SUBTOTAL = 'FFFFF3E0', BORDE = 'FFE0E0E0';
  const DINERO = '"₡"#,##0';
  const relleno = c => ({ type: 'pattern', pattern: 'solid', fgColor: { argb: c } });
  const borde = { top: { style: 'thin', color: { argb: BORDE } }, bottom: { style: 'thin', color: { argb: BORDE } } };
  const fechaExcel = s => aUTC(s);
  const libro = new ExcelJS.Workbook();
  libro.creator = 'Pizza Yaja ERP'; libro.created = new Date();

  // Agrupar órdenes y eliminadas por semana (lunes)
  const porSemana = (lista) => { const m = {}; lista.forEach(x => (m[lunesDe(x.fecha)] ??= []).push(x)); return m; };
  const ordSem = porSemana(D.ordenes), eliSem = porSemana(D.eliminadas);

  // Encabezado común de cada hoja: título, período y columnas
  function hoja(nombre, columnas) {
    const h = libro.addWorksheet(nombre, { views: [{ state: 'frozen', ySplit: 5 }] });
    h.columns = columnas.map(c => ({ key: c.k, width: c.w }));
    const n = columnas.length;
    h.mergeCells(1, 1, 1, n); h.mergeCells(2, 1, 2, n); h.mergeCells(3, 1, 3, n);
    h.getCell(1, 1).value = 'Pizza Yaja — ' + nombre;
    h.getCell(1, 1).font = { bold: true, size: 16, color: { argb: NARANJA } };
    h.getCell(2, 1).value = 'Del ' + fLarga(D.desde) + ' al ' + fLarga(D.hasta) + '   ·   ' + D.sucursal;
    h.getCell(2, 1).font = { bold: true, size: 11 };
    h.getCell(3, 1).value = 'Generado el ' + D.generado;
    h.getCell(3, 1).font = { italic: true, size: 9, color: { argb: 'FF888888' } };
    const enc = h.getRow(5);
    columnas.forEach((c, i) => {
      const cel = enc.getCell(i + 1);
      cel.value = c.t; cel.font = { bold: true, color: { argb: 'FFFFFFFF' } }; cel.fill = relleno(OSCURO);
      cel.alignment = { vertical: 'middle', horizontal: c.dinero || c.num ? 'right' : 'left' };
    });
    enc.height = 22;
    h._cols = columnas;
    return h;
  }
  function filaSemana(h, texto) {
    const f = h.addRow([]); const n = h._cols.length;
    h.mergeCells(f.number, 1, f.number, n);
    f.getCell(1).value = '📅 ' + texto;
    f.getCell(1).font = { bold: true, size: 12, color: { argb: 'FF8D4A00' } };
    f.getCell(1).fill = relleno(SEMANA); f.height = 20;
  }
  function filaDatos(h, obj, estilo) {
    const f = h.addRow(obj);
    h._cols.forEach((c, i) => {
      const cel = f.getCell(i + 1);
      if (c.dinero) cel.numFmt = DINERO;
      if (c.fecha && cel.value) cel.numFmt = 'dd/mm/yyyy';
      if (c.wrap) cel.alignment = { wrapText: true, vertical: 'top' };
      else cel.alignment = { vertical: 'top', horizontal: c.dinero || c.num ? 'right' : 'left' };
      cel.border = borde;
      if (estilo === 'sub') { cel.font = { bold: true }; cel.fill = relleno(SUBTOTAL); }
      if (estilo === 'total') { cel.font = { bold: true, color: { argb: 'FFFFFFFF' }, size: 12 }; cel.fill = relleno(NARANJA); }
    });
    return f;
  }
  const sumar = (lista, k) => lista.reduce((s, x) => s + (x[k] || 0), 0);

  // ── Hoja 1: Resumen por día ──
  const h1 = hoja('Resumen', [
    { k: 'dia', t: 'Día', w: 8 }, { k: 'fecha', t: 'Fecha', w: 12, fecha: true }, { k: 'ordenes', t: 'Órdenes', w: 10, num: true },
    { k: 'efectivo', t: 'Efectivo', w: 14, dinero: true }, { k: 'sinpe', t: 'SINPE', w: 14, dinero: true },
    { k: 'tarjeta', t: 'Tarjeta', w: 14, dinero: true }, { k: 'total', t: 'Total vendido', w: 16, dinero: true },
    { k: 'calzones', t: 'Calzones', w: 10, num: true },
  ]);
  const gran = { ordenes: 0, efectivo: 0, sinpe: 0, tarjeta: 0, total: 0, calzones: 0 };
  D.semanas.forEach(s => {
    filaSemana(h1, s.titulo);
    const sub = { ordenes: 0, efectivo: 0, sinpe: 0, tarjeta: 0, total: 0, calzones: 0 };
    s.dias.forEach(d => {
      const os = D.ordenes.filter(o => o.fecha === d.fecha);
      const fila = { dia: d.dia, fecha: fechaExcel(d.fecha), ordenes: os.length,
        efectivo: sumar(os, 'efectivo'), sinpe: sumar(os, 'sinpe'), tarjeta: sumar(os, 'tarjeta'),
        total: sumar(os, 'total'), calzones: d.calzones };
      filaDatos(h1, fila);
      Object.keys(sub).forEach(k => sub[k] += fila[k]);
    });
    filaDatos(h1, { dia: 'Total semana', ...sub }, 'sub');
    Object.keys(gran).forEach(k => gran[k] += sub[k]);
    h1.addRow([]);
  });
  filaDatos(h1, { dia: 'TOTAL', ...gran }, 'total');

  // ── Hoja 2: Órdenes ──
  const colsOrd = [
    { k: 'fecha', t: 'Fecha', w: 12, fecha: true }, { k: 'hora', t: 'Hora', w: 7 }, { k: 'numero', t: '# Orden', w: 9, num: true },
    { k: 'cliente', t: 'Cliente / Mesa', w: 24 },
  ];
  if (D.variasSuc) colsOrd.push({ k: 'sucursal', t: 'Sucursal', w: 11 });
  colsOrd.push({ k: 'efectivo', t: 'Efectivo', w: 13, dinero: true }, { k: 'sinpe', t: 'SINPE', w: 13, dinero: true },
               { k: 'tarjeta', t: 'Tarjeta', w: 13, dinero: true }, { k: 'total', t: 'Total', w: 13, dinero: true },
               { k: 'productos', t: 'Productos', w: 70, wrap: true });
  const h2 = hoja('Órdenes', colsOrd);
  D.semanas.forEach(s => {
    const os = ordSem[s.lunes] || [];
    filaSemana(h2, s.titulo + '   (' + os.length + (os.length === 1 ? ' orden)' : ' órdenes)'));
    os.forEach(o => filaDatos(h2, { ...o, fecha: fechaExcel(o.fecha) }));
    filaDatos(h2, { cliente: 'Total semana', efectivo: sumar(os, 'efectivo'), sinpe: sumar(os, 'sinpe'),
                    tarjeta: sumar(os, 'tarjeta'), total: sumar(os, 'total') }, 'sub');
    h2.addRow([]);
  });
  filaDatos(h2, { cliente: 'TOTAL', efectivo: gran.efectivo, sinpe: gran.sinpe, tarjeta: gran.tarjeta, total: gran.total }, 'total');

  // ── Hoja 3: Productos vendidos ──
  const h3 = hoja('Productos', [
    { k: 'nombre', t: 'Producto', w: 46 }, { k: 'cantidad', t: 'Cantidad', w: 11, num: true }, { k: 'total', t: 'Total', w: 15, dinero: true },
  ]);
  const todo = {};
  D.semanas.forEach(s => {
    filaSemana(h3, s.titulo);
    s.productos.forEach(p => {
      filaDatos(h3, p);
      todo[p.nombre] ??= { nombre: p.nombre, cantidad: 0, total: 0 };
      todo[p.nombre].cantidad += p.cantidad; todo[p.nombre].total += p.total;
    });
    if (!s.productos.length) filaDatos(h3, { nombre: 'Sin ventas esta semana' });
    filaDatos(h3, { nombre: 'Total semana', cantidad: sumar(s.productos, 'cantidad'), total: sumar(s.productos, 'total') }, 'sub');
    h3.addRow([]);
  });
  if (D.semanas.length > 1) {
    filaSemana(h3, 'Todo el período');
    const lista = Object.values(todo).sort((a, b) => b.cantidad - a.cantidad || a.nombre.localeCompare(b.nombre));
    lista.forEach(p => filaDatos(h3, p));
    filaDatos(h3, { nombre: 'TOTAL', cantidad: sumar(lista, 'cantidad'), total: sumar(lista, 'total') }, 'total');
  }

  // ── Hoja 4: Eliminadas ──
  const colsEli = [
    { k: 'fecha', t: 'Fecha', w: 12, fecha: true }, { k: 'hora', t: 'Hora', w: 7 }, { k: 'numero', t: '# Orden', w: 9, num: true },
    { k: 'cliente', t: 'Cliente / Mesa', w: 24 },
  ];
  if (D.variasSuc) colsEli.push({ k: 'sucursal', t: 'Sucursal', w: 11 });
  colsEli.push({ k: 'total', t: 'Total', w: 13, dinero: true }, { k: 'motivo', t: 'Motivo', w: 44, wrap: true }, { k: 'por', t: 'Eliminada por', w: 18 });
  const h4 = hoja('Eliminadas', colsEli);
  D.semanas.forEach(s => {
    const es = eliSem[s.lunes] || [];
    filaSemana(h4, s.titulo + '   (' + es.length + (es.length === 1 ? ' eliminada)' : ' eliminadas)'));
    if (!es.length) filaDatos(h4, { cliente: 'No hubo órdenes eliminadas' });
    es.forEach(e => filaDatos(h4, { ...e, fecha: fechaExcel(e.fecha) }));
    h4.addRow([]);
  });

  return libro;
}

function verDetalle(id) {
  document.getElementById('mModal').style.display = 'flex';
  document.getElementById('mBody').innerHTML = 'Cargando...';
  fetch('../detalle_orden_ajax.php?orden_id=' + id)
    .then(r => r.json())
    .then(data => {
      if(!data.success) { document.getElementById('mBody').innerHTML = 'Error al cargar'; return; }
      const o = data.orden;
      document.getElementById('mTitle').textContent = 'Orden #' + o.numero_orden;
      let html = `<p><strong>Cliente:</strong> ${esc(o.nombre_cliente || '—')}</p>
        <p><strong>Método:</strong> ${esc(o.metodo_pago || '—')}</p>
        <p><strong>Fecha:</strong> ${esc(o.fecha_creacion)}</p>
        <hr style="margin:12px 0;border:none;border-top:1px solid #eee">
        <table style="width:100%;font-size:13px;border-collapse:collapse">
          <thead><tr style="border-bottom:2px solid #eee">
            <th style="padding:6px;text-align:left">Producto</th>
            <th style="padding:6px;text-align:center">Cant.</th>
            <th style="padding:6px;text-align:right">Subtotal</th>
          </tr></thead><tbody>`;
      (data.items || []).forEach(i => {
        html += `<tr style="border-bottom:1px solid #f5f5f5">
          <td style="padding:7px 6px">${esc(i.nombre)}</td>
          <td style="padding:7px 6px;text-align:center">${esc(i.cantidad)}</td>
          <td style="padding:7px 6px;text-align:right">₡${Number(i.subtotal).toLocaleString()}</td>
        </tr>`;
      });
      html += `</tbody></table>
        <div style="text-align:right;margin-top:12px;font-size:18px;font-weight:800;color:#ff6b00">
          Total: ₡${Number(o.total).toLocaleString()}
        </div>`;
      document.getElementById('mBody').innerHTML = html;
    });
}
function cerrarModal() { document.getElementById('mModal').style.display = 'none'; }

function eliminarLog(id, btn) {
  if(!confirm('¿Eliminar este registro del log? Esta acción no se puede deshacer.')) return;
  btn.disabled = true;
  fetch('historial.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({accion:'eliminar_log', id})
  }).then(r => r.json()).then(d => {
    if(d.success) btn.closest('tr').remove();
    else { alert('Error: ' + d.error); btn.disabled = false; }
  });
}
</script>
</body>
</html>
