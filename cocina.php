<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'cocina') {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cocina - Pizza Yaja</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            background: #1a1a1a;
            color: white;
            padding: 15px;
        }

        .header {
            background: #ff9800;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 { font-size: 22px; color: white; }

        .logout-btn {
            background: rgba(0,0,0,0.2);
            color: white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
        }

        #contador_ordenes {
            font-size: 14px;
            color: rgba(255,255,255,0.8);
        }

        .ordenes-grid {
            display: grid;
            gap: 15px;
        }

        .orden-card {
            background: #2a2a2a;
            border: 3px solid #ff9800;
            border-radius: 10px;
            padding: 15px;
            transition: border-color 0.3s;
        }

        /* Orden nueva (no recibida) = borde rojo parpadeante */
        .orden-card.nueva {
            border-color: #f44336;
            animation: parpadear 1s infinite;
        }

        @keyframes parpadear {
            0%, 100% { border-color: #f44336; }
            50% { border-color: #ff9800; }
        }

        .orden-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #444;
        }

        .orden-numero {
            font-size: 20px;
            font-weight: bold;
            color: #ff9800;
        }

        .orden-cliente {
            font-size: 14px;
            color: #aaa;
            margin-top: 3px;
        }

        .badge-nueva {
            background: #f44336;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
        }

        .cronometro {
            font-size: 18px;
            font-weight: bold;
            font-family: monospace;
            color: #aaa;
        }

        .items-lista {
            margin: 10px 0;
        }

        .item {
            background: #333;
            padding: 10px 12px;
            border-radius: 5px;
            margin-bottom: 6px;
            font-size: 16px;
        }

        .item-cantidad {
            background: #ff9800;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            margin-right: 8px;
            font-weight: bold;
            font-size: 14px;
        }

        .item-nota {
            font-size: 13px;
            color: #ff9800;
            margin-top: 5px;
            padding-left: 8px;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 12px;
        }

        .btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-recibido {
            background: #2196f3;
            color: white;
        }

        .btn-listo {
            background: #4caf50;
            color: white;
            font-size: 18px;
        }

        .no-ordenes {
            background: #2a2a2a;
            padding: 60px 20px;
            border-radius: 10px;
            text-align: center;
            color: #666;
        }

        .no-ordenes h2 { font-size: 24px; margin-bottom: 10px; }

        /* Overlay de activación de sonido */
        #overlay-sonido {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.92);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 20px;
        }

        #overlay-sonido h2 {
            font-size: 28px;
            color: #ff9800;
            margin-bottom: 15px;
        }

        #overlay-sonido p {
            color: #aaa;
            margin-bottom: 30px;
            font-size: 16px;
        }

        #btn-activar {
            background: #ff9800;
            color: white;
            border: none;
            padding: 20px 50px;
            font-size: 22px;
            font-weight: bold;
            border-radius: 10px;
            cursor: pointer;
        }
    </style>
</head>
<body>

    <!-- Overlay: el cocinero toca esto una vez para activar el audio -->
    <div id="overlay-sonido">
        <h2>🍕 Pizza Yaja — Cocina</h2>
        <p>Toca el botón para activar las notificaciones de sonido</p>
        <button id="btn-activar" onclick="activarSonido()">Iniciar</button>
    </div>

    <div class="header">
        <div>
            <h1>👨‍🍳 Cocina</h1>
            <span id="contador_ordenes">Cargando...</span>
        </div>
        <a href="logout.php" class="logout-btn">Salir</a>
    </div>

    <div class="ordenes-grid" id="ordenes-container">
        <div class="no-ordenes">
            <h2>Sin pedidos</h2>
            <p>Los pedidos aparecerán aquí automáticamente</p>
        </div>
    </div>

    <script>
        // ─── TIMBRE ──────────────────────────────────────────────
        let timbreInterval = null;
        let timbreSegundos = 0;
        const TIMBRE_MAX_SEGUNDOS = 120;
        let audioActivado = false;

        function activarSonido() {
            // Reproducir un beep silencioso para "desbloquear" el audio del navegador
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            gain.gain.value = 0.001; // casi silencioso
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.1);

            audioActivado = true;
            document.getElementById('overlay-sonido').style.display = 'none';
            verificarOrdenes(); // primera consulta inmediata
        }

        // Genera un "ding" corto
        function sonarTono(frecuencia, delay, duracion) {
            if(!audioActivado) return;
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.value = frecuencia;
                gain.gain.setValueAtTime(0.5, ctx.currentTime + delay);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + delay + duracion);
                osc.start(ctx.currentTime + delay);
                osc.stop(ctx.currentTime + delay + duracion + 0.05);
            } catch(e) {}
        }

        // "Ding-Dong"
        function sonarDingDong() {
            sonarTono(880, 0, 0.18);
            sonarTono(660, 0.25, 0.18);
        }

        function iniciarTimbre() {
            if(timbreInterval) return; // ya está sonando
            timbreSegundos = 0;
            sonarDingDong();
            timbreInterval = setInterval(() => {
                timbreSegundos += 3;
                if(timbreSegundos >= TIMBRE_MAX_SEGUNDOS) {
                    pararTimbre();
                    return;
                }
                sonarDingDong();
            }, 3000);
        }

        function pararTimbre() {
            if(timbreInterval) {
                clearInterval(timbreInterval);
                timbreInterval = null;
            }
        }

        // ─── POLLING (consulta cada 5 segundos) ──────────────────
        let ordenesAnteriores = new Set();

        async function verificarOrdenes() {
            try {
                const resp = await fetch('get_ordenes_cocina.php');
                const data = await resp.json();

                if(!data.success) return;

                const ordenes = data.ordenes;

                // Detectar si hay órdenes nuevas (IDs que no estaban antes)
                let hayNuevas = false;
                ordenes.forEach(o => {
                    if(!ordenesAnteriores.has(o.id)) {
                        hayNuevas = true;
                    }
                    ordenesAnteriores.add(o.id);
                });

                // Actualizar la pantalla
                renderOrdenes(ordenes);

                // Manejar el timbre
                const hayNoNotificadas = ordenes.some(o => o.cocina_notificado == 0);
                if(hayNoNotificadas) {
                    iniciarTimbre();
                } else {
                    pararTimbre();
                }

            } catch(e) {
                console.error('Error al consultar órdenes:', e);
            }
        }

        // ─── RENDER (dibuja las tarjetas de órdenes) ─────────────
        function renderOrdenes(ordenes) {
            const container = document.getElementById('ordenes-container');
            const contador = document.getElementById('contador_ordenes');

            contador.textContent = ordenes.length > 0
                ? `${ordenes.length} pedido(s) activo(s)`
                : 'Sin pedidos';

            if(ordenes.length === 0) {
                container.innerHTML = `
                    <div class="no-ordenes">
                        <h2>Sin pedidos</h2>
                        <p>Los pedidos aparecerán aquí automáticamente</p>
                    </div>`;
                return;
            }

            container.innerHTML = ordenes.map(orden => {
                const esNueva = orden.cocina_notificado == 0;
                const fechaCreacion = new Date(orden.fecha_creacion);
                const tiempoTranscurrido = calcularTiempo(fechaCreacion);

                const itemsHtml = orden.items.map(item => `
                    <div class="item">
                        <span class="item-cantidad">${item.cantidad}x</span>
                        ${escHtml(item.producto_nombre)}
                        ${item.notas ? `<div class="item-nota">💬 ${escHtml(item.notas)}</div>` : ''}
                    </div>
                `).join('');

                const btnRecibido = esNueva
                    ? `<button class="btn btn-recibido" onclick="marcarRecibido(${orden.id})">✅ Recibido</button>`
                    : '';

                return `
                    <div class="orden-card ${esNueva ? 'nueva' : ''}" id="orden_${orden.id}">
                        <div class="orden-header">
                            <div>
                                <div class="orden-numero">Orden #${orden.numero_orden}</div>
                                <div class="orden-cliente">
                                    ${escHtml(orden.nombre_cliente)} &nbsp;·&nbsp;
                                    ${orden.tipo_servicio === 'express' ? '📦 Para llevar' : '🍽️ Comer aquí'}
                                </div>
                            </div>
                            <div>
                                ${esNueva ? '<span class="badge-nueva">¡NUEVA!</span>' : ''}
                                <div class="cronometro">${tiempoTranscurrido}</div>
                            </div>
                        </div>

                        <div class="items-lista">${itemsHtml}</div>

                        <div class="btn-group">
                            ${btnRecibido}
                            <button class="btn btn-listo" onclick="marcarListo(${orden.id})">🍕 Listo</button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // ─── ACCIONES ─────────────────────────────────────────────
        async function marcarRecibido(ordenId) {
            await fetch('marcar_recibido.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({orden_id: ordenId})
            });
            verificarOrdenes();
        }

        async function marcarListo(ordenId) {
            await fetch('marcar_listo.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({orden_id: ordenId})
            });
            // Quitar la orden de la pantalla inmediatamente
            const card = document.getElementById('orden_' + ordenId);
            if(card) card.remove();
            ordenesAnteriores.delete(ordenId);
            verificarOrdenes();
        }

        // ─── UTILIDADES ───────────────────────────────────────────
        function calcularTiempo(fecha) {
            const diff = Math.floor((new Date() - fecha) / 1000);
            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);
            const s = diff % 60;
            return `${pad(h)}:${pad(m)}:${pad(s)}`;
        }

        function pad(n) { return String(n).padStart(2, '0'); }

        function escHtml(str) {
            return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

        // Actualizar cronómetros cada segundo
        setInterval(() => {
            document.querySelectorAll('.cronometro').forEach(el => {
                const card = el.closest('.orden-card');
                if(!card) return;
                const id = card.id.replace('orden_', '');
                // Los cronómetros se actualizan en el próximo render del polling
            });
        }, 1000);

        // Polling cada 2 segundos (se activa al tocar "Iniciar")
        setInterval(() => {
            if(audioActivado) verificarOrdenes();
        }, 2000);
    </script>
</body>
</html>
