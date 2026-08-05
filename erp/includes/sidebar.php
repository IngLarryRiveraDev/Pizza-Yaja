<?php
$currentPage = basename($_SERVER['PHP_SELF']);
function navItem($href, $icon, $label, $cur) {
    $a = basename($href) === $cur ? 'active' : '';
    return "<a href=\"{$href}\" class=\"erp-nav-item {$a}\"><span class=\"ni\">{$icon}</span>{$label}</a>";
}
?>
<style>
:root {
  --sb-w:   220px;
  --sb-bg:  #111;
  --bar-h:  56px;
  --bg:     #f0f2f5;
  --card:   #fff;
  --orange: #ff9800;
  --ora2:   #ff6b00;
  --text:   #1a1a1a;
  --muted:  #888;
  --border: #e0e0e0;
  --green:  #4caf50;
  --red:    #e53935;
  --blue:   #2196f3;
  --purple: #9c27b0;
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; }
body.erp { font-family: 'Segoe UI', Arial, sans-serif; background: var(--bg); color: var(--text); }
a { text-decoration: none; color: inherit; }

/* ── SIDEBAR ── */
.erp-sb {
  position: fixed; top: 0; left: 0; bottom: 0; width: var(--sb-w);
  background: var(--sb-bg); display: flex; flex-direction: column;
  z-index: 200; transition: transform .25s ease;
}
.erp-brand { padding: 18px 16px 14px; border-bottom: 1px solid #1d1d1d; }
.erp-brand-name {
  font-size: 20px; font-weight: 900;
  background: linear-gradient(135deg, #ff6b00, #ff9800);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.erp-brand-tag { font-size: 10px; color: #555; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 2px; }

.erp-nav { flex: 1; padding: 8px 0; overflow-y: auto; }
.erp-nav-sec { font-size: 10px; color: #444; letter-spacing: 1.5px; text-transform: uppercase; padding: 12px 16px 4px; }
.erp-nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 16px; color: #999; font-size: 13px; font-weight: 500;
  border-left: 3px solid transparent; transition: all .15s;
}
.erp-nav-item:hover { background: #1a1a1a; color: #fff; }
.erp-nav-item.active { background: rgba(255,107,0,.1); color: var(--orange); border-left-color: var(--orange); }
.erp-nav-item .ni { font-size: 15px; width: 20px; text-align: center; flex-shrink: 0; }

.erp-sb-foot { padding: 12px 16px; border-top: 1px solid #1d1d1d; }
.erp-sb-usr  { font-size: 11px; color: #555; margin-bottom: 2px; }
.erp-sb-name { font-size: 13px; color: #bbb; font-weight: 600; margin-bottom: 10px; }
.erp-sb-logout {
  display: block; width: 100%; padding: 8px; text-align: center;
  background: #c62828; color: #fff; border: none; border-radius: 6px;
  font-size: 13px; font-weight: 700; cursor: pointer;
}

/* ── OVERLAY ── */
.erp-ov { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 199; }
.erp-ov.on { display: block; }

/* ── MAIN ── */
.erp-main { margin-left: var(--sb-w); min-height: 100vh; display: flex; flex-direction: column; transition: margin-left .25s; }

/* ── TOPBAR ── */
.erp-topbar {
  height: var(--bar-h); background: var(--card); border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 12px; padding: 0 20px;
  position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.erp-hbg { background: none; border: none; cursor: pointer; font-size: 20px; color: #666; padding: 4px; border-radius: 4px; display: none; }
.erp-pg-title { font-size: 17px; font-weight: 700; }
.erp-topbar-right { margin-left: auto; font-size: 13px; color: var(--muted); }

/* ── CONTENT ── */
.erp-content { padding: 20px; flex: 1; }

/* ── KPI ── */
.kpi-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 14px; margin-bottom: 18px; }
.kpi {
  background: var(--card); border-radius: 12px; padding: 18px 20px;
  box-shadow: 0 1px 5px rgba(0,0,0,.06); border: 1px solid var(--border);
  position: relative; overflow: hidden;
}
.kpi::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.kpi.ora::before { background: linear-gradient(90deg, var(--ora2), var(--orange)); }
.kpi.grn::before { background: var(--green); }
.kpi.blu::before { background: var(--blue); }
.kpi.pur::before { background: var(--purple); }
.kpi.red::before { background: var(--red); }
.kpi-lbl  { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 8px; }
.kpi-val  { font-size: 26px; font-weight: 800; line-height: 1; }
.kpi-sub  { font-size: 12px; color: var(--muted); margin-top: 6px; }
.kpi-icon { position: absolute; right: 14px; top: 14px; font-size: 26px; opacity: .12; }
.up { color: var(--green); font-weight: 700; }
.dn { color: var(--red);   font-weight: 700; }

/* ── LAYOUT GRID ── */
.eg2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.eg3 { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 16px; }

/* ── CARD ── */
.ec { background: var(--card); border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 1px 5px rgba(0,0,0,.06); overflow: hidden; margin-bottom: 16px; }
.ec.nm { margin-bottom: 0; }
.ec-head { padding: 14px 18px; border-bottom: 1px solid var(--border); font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.ec-body { padding: 16px 18px; }
.ec-body.np { padding: 0; }

/* ── TABLE ── */
.et { width: 100%; border-collapse: collapse; font-size: 13px; }
.et th { padding: 9px 14px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); border-bottom: 2px solid var(--border); }
.et td { padding: 10px 14px; border-bottom: 1px solid #f5f5f5; }
.et tr:last-child td { border-bottom: none; }
.et tr:hover td { background: #fafafa; }

/* ── FORM ── */
.ef { width: 100%; padding: 9px 12px; border: 2px solid var(--border); border-radius: 8px; font-size: 14px; transition: border-color .15s; }
.ef:focus { outline: none; border-color: var(--orange); }
.ef-label { display: block; font-size: 12px; font-weight: 700; color: #555; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .3px; }
.ef-group { margin-bottom: 12px; }
.eb { padding: 9px 18px; border: none; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; transition: all .15s; }
.eb:hover { opacity: .88; }
.eb.ora { background: var(--orange); color: #fff; }
.eb.grn { background: var(--green); color: #fff; }
.eb.red { background: var(--red); color: #fff; }
.eb.gry { background: #eee; color: #555; }
.eb.full { width: 100%; padding: 11px; }

/* ── BADGE ── */
.bdg { padding: 3px 9px; border-radius: 50px; font-size: 11px; font-weight: 700; }
.bdg-grn { background: #e8f5e9; color: #2e7d32; }
.bdg-org { background: #fff3e0; color: #e65100; }
.bdg-blu { background: #e3f2fd; color: #1565c0; }
.bdg-pur { background: #f3e5f5; color: #6a1b9a; }
.bdg-red { background: #ffebee; color: #b71c1c; }
.bdg-gry { background: #f5f5f5; color: #555; }

/* ── CHART ── */
.cw { position: relative; height: 220px; }
.cw.sm { height: 180px; }
.cw.lg { height: 300px; }

/* ── TABLES: scroll horizontal en pantallas chicas ── */
.ec-body.np { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.et { min-width: 480px; }

/* ── RESPONSIVE ── */
@media (max-width: 900px) { .eg2, .eg3 { grid-template-columns: 1fr; } }

@media (max-width: 768px) {
  /* Sidebar off-canvas */
  .erp-sb { transform: translateX(-100%); }
  .erp-sb.open { transform: translateX(0); }
  .erp-main { margin-left: 0; }
  .erp-hbg { display: block; }
  .erp-content { padding: 10px; }

  /* KPIs 2 columnas */
  .kpi-row { grid-template-columns: 1fr 1fr; }

  /* Topbar: permite que el lado derecho baje si hay muchos botones */
  .erp-topbar {
    flex-wrap: wrap;
    height: auto;
    min-height: var(--bar-h);
    padding: 8px 12px;
    gap: 6px;
  }
  .erp-topbar-right {
    margin-left: auto;
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    align-items: center;
  }

  /* Botones más compactos en topbar mobile */
  .erp-topbar .eb { padding: 5px 10px; font-size: 12px; }

  /* Modales más anchos */
  .erp-topbar + div [style*="max-width:440px"],
  .erp-topbar + div [style*="max-width:420px"] { width: 96%; }
}

@media (max-width: 480px) {
  /* KPIs a 1 columna en pantallas muy chicas */
  .kpi-row { grid-template-columns: 1fr; }
  .kpi-val { font-size: 22px; }

  /* Encabezado de topbar solo muestra ícono + título */
  .erp-topbar-right { width: 100%; justify-content: flex-start; }

  /* Padding mínimo */
  .erp-content { padding: 8px; }
  .ec-head { padding: 10px 12px; font-size: 13px; }
  .ec-body { padding: 10px 12px; }
}
</style>

<aside class="erp-sb" id="erp-sb">
  <div class="erp-brand">
    <div class="erp-brand-name">Pizza Yaja</div>
    <div class="erp-brand-tag">Sistema ERP</div>
  </div>

  <nav class="erp-nav">
    <div class="erp-nav-sec">Principal</div>
    <?= navItem('index.php',      '📊', 'Dashboard',   $currentPage) ?>

    <div class="erp-nav-sec">Operaciones</div>
    <?= navItem('ventas.php',     '📈', 'Ventas',      $currentPage) ?>
    <?= navItem('historial.php',  '📋', 'Historial',   $currentPage) ?>
    <?= navItem('inventario.php', '📦', 'Inventario',  $currentPage) ?>
    <?= navItem('gastos.php',     '💸', 'Gastos',      $currentPage) ?>

    <div class="erp-nav-sec">Configuración</div>
    <?= navItem('productos.php',  '🍕', 'Productos',   $currentPage) ?>
    <?= navItem('usuarios.php',   '👥', 'Usuarios',    $currentPage) ?>
  </nav>

  <div class="erp-sb-foot">
    <div class="erp-sb-usr">Conectado como</div>
    <div class="erp-sb-name"><?= htmlspecialchars($_SESSION['nombre']) ?></div>
    <a href="../logout.php" class="erp-sb-logout">Salir del sistema</a>
  </div>
</aside>

<div class="erp-ov" id="erp-ov" onclick="erpHbg()"></div>

<script>
function erpHbg() {
  document.getElementById('erp-sb').classList.toggle('open');
  document.getElementById('erp-ov').classList.toggle('on');
}
</script>
