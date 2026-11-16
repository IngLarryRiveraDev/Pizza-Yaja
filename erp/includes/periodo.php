<?php
// ── Períodos del ERP: semana (lunes a domingo) o mes de calendario ──────────
// Uso:  $P = periodoActual();   y en el HTML:  periodoNav($P);
// En las consultas: fecha >= $P['inicio'] AND fecha < $P['fin']  (fin es exclusivo)
//
// URL: ?vista=semana|mes&ref=AAAA-MM-DD   (ref = cualquier día dentro del período)

const PER_DIAS_CORTO = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const PER_MESES      = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
const PER_MESES_C    = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];

// Lunes de la semana de una fecha (la semana va de lunes a domingo)
function perLunes($fecha) {
    $ts = strtotime($fecha);
    return date('Y-m-d', strtotime('-' . (date('N', $ts) - 1) . ' days', $ts));
}

function perFechaCorta($fecha) {
    $ts = strtotime($fecha);
    return date('j', $ts) . ' ' . PER_MESES_C[date('n', $ts) - 1];
}

function periodoActual() {
    $hoy   = date('Y-m-d');
    $vista = ($_GET['vista'] ?? '') === 'mes' ? 'mes' : 'semana';
    $ref   = $_GET['ref'] ?? $hoy;
    $dt    = DateTime::createFromFormat('Y-m-d', $ref);
    if(!$dt || $dt->format('Y-m-d') !== $ref || $ref > $hoy) $ref = $hoy;

    if($vista === 'semana') {
        $inicio = perLunes($ref);
        $fin    = date('Y-m-d', strtotime($inicio . ' +7 days'));
        $domingo = date('Y-m-d', strtotime($inicio . ' +6 days'));
        $titulo = 'Semana del ' . PER_DIAS_CORTO[0] . ' ' . perFechaCorta($inicio) . ' al ' . PER_DIAS_CORTO[6] . ' ' . perFechaCorta($domingo);
        $anterior  = date('Y-m-d', strtotime($inicio . ' -7 days'));
        $siguiente = $fin;
        $nombreCorto = 'esta semana';
        $nombreAnt   = 'semana anterior';
    } else {
        $inicio = date('Y-m-01', strtotime($ref));
        $fin    = date('Y-m-01', strtotime($inicio . ' +1 month'));
        $ts     = strtotime($inicio);
        $titulo = ucfirst(PER_MESES[date('n', $ts) - 1]) . ' ' . date('Y', $ts);
        $anterior  = date('Y-m-01', strtotime($inicio . ' -1 month'));
        $siguiente = $fin;
        $nombreCorto = 'este mes';
        $nombreAnt   = 'mes anterior';
    }
    $esActual = $hoy >= $inicio && $hoy < $fin;

    // Días del período (para gráficos y tablas)
    $dias = [];
    for($d = $inicio; $d < $fin; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $ts = strtotime($d);
        $dias[$d] = [
            'fecha'   => $d,
            'etiqueta'=> $vista === 'semana' ? PER_DIAS_CORTO[date('N', $ts) - 1] . ' ' . date('j', $ts) : date('j', $ts),
            'futuro'  => $d > $hoy,
        ];
    }

    // Semanas (lunes a domingo) que caen dentro del mes, recortadas al mes
    $semanas = [];
    if($vista === 'mes') {
        for($l = perLunes($inicio); $l < $fin; $l = date('Y-m-d', strtotime($l . ' +7 days'))) {
            $desde = max($l, $inicio);
            $hasta = min(date('Y-m-d', strtotime($l . ' +7 days')), $fin); // exclusivo
            $ultimo = date('Y-m-d', strtotime($hasta . ' -1 day'));
            $semanas[] = [
                'lunes'    => $l,
                'desde'    => $desde,
                'hasta'    => $hasta,
                'etiqueta' => $desde === $ultimo ? perFechaCorta($desde) : perFechaCorta($desde) . ' – ' . perFechaCorta($ultimo),
                'completa' => $desde === $l && $hasta === date('Y-m-d', strtotime($l . ' +7 days')),
            ];
        }
    }

    // Período anterior, para comparar
    $antInicio = $anterior;
    $antFin    = $inicio;

    return compact('vista', 'ref', 'inicio', 'fin', 'titulo', 'anterior', 'siguiente', 'esActual',
                   'dias', 'semanas', 'antInicio', 'antFin', 'nombreCorto', 'nombreAnt', 'hoy');
}

// Enlace conservando otros parámetros de la página
function perUrl($P, $cambios) {
    $q = array_merge($_GET, ['vista' => $P['vista'], 'ref' => $P['ref']], $cambios);
    unset($q['suc']);
    return '?' . http_build_query($q);
}

// Variación contra el período anterior: "↑ 12% vs semana anterior"
function perVariacion($ahora, $antes, $P, $invertir = false) {
    if($antes == 0) return $ahora > 0 ? 'sin datos del período anterior' : '';
    $p = ($ahora - $antes) / $antes * 100;
    $sube = $p >= 0;
    $bueno = $invertir ? !$sube : $sube;
    $color = $bueno ? '#2e7d32' : '#c62828';
    return '<span style="color:' . $color . ';font-weight:700">' . ($sube ? '↑' : '↓') . ' ' . number_format(abs($p), 1) . '%</span> vs ' . $P['nombreAnt'];
}

// Barra de navegación: [Semana|Mes]  ←  título  →  [Ir a hoy]
function periodoNav($P) { ?>
  <div class="ec" style="margin-bottom:16px">
    <div class="ec-body" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <a class="eb gry" style="padding:8px 14px;font-size:16px" href="<?= perUrl($P, ['ref' => $P['anterior']]) ?>" title="Anterior">←</a>
        <div style="font-size:18px;font-weight:800">
          <?= htmlspecialchars($P['titulo']) ?>
          <?php if($P['esActual']): ?><small style="display:block;font-size:12px;font-weight:600;color:var(--orange)"><?= $P['vista'] === 'semana' ? 'SEMANA ACTUAL' : 'MES ACTUAL' ?></small><?php endif; ?>
        </div>
        <a class="eb gry" style="padding:8px 14px;font-size:16px;<?= $P['esActual'] ? 'opacity:.35;pointer-events:none' : '' ?>" href="<?= perUrl($P, ['ref' => $P['siguiente']]) ?>" title="Siguiente">→</a>
      </div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <a href="<?= perUrl($P, ['vista' => 'semana']) ?>" class="eb <?= $P['vista'] === 'semana' ? 'ora' : 'gry' ?>" style="padding:6px 14px;font-size:13px">Semana</a>
        <a href="<?= perUrl($P, ['vista' => 'mes']) ?>" class="eb <?= $P['vista'] === 'mes' ? 'ora' : 'gry' ?>" style="padding:6px 14px;font-size:13px">Mes</a>
        <?php if(!$P['esActual']): ?><a href="<?= perUrl($P, ['ref' => $P['hoy']]) ?>" class="eb ora" style="padding:6px 14px;font-size:13px">Ir a hoy</a><?php endif; ?>
      </div>
    </div>
  </div>
<?php }
