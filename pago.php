<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$orden_id = $_GET['orden_id'] ?? 0;

// ConexiÃ³n BD
require_once 'config.php';

try {
    $conn = getConnection();
    
    // Obtener informaciÃ³n de la orden
    $stmt = $conn->prepare("SELECT * FROM ordenes WHERE id = ?");
    $stmt->execute([$orden_id]);
    $orden = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$orden) {
        die("Orden no encontrada");
    }
    
    // Verificar cuÃ¡nto ya se ha pagado
    $stmt = $conn->prepare("SELECT SUM(monto_aplicado) as pagado FROM pagos WHERE orden_id = ?");
    $stmt->execute([$orden_id]);
    $pago_previo = $stmt->fetch(PDO::FETCH_ASSOC);
    $ya_pagado = $pago_previo['pagado'] ?? 0;
    
    $pendiente_inicial = $orden['total'] - $ya_pagado;
    
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago - Orden #<?php echo $orden['numero_orden']; ?></title>
    <script src="notificacion.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            padding: 15px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            padding: 12px;
            background: #4a90e2;
            color: white;
            border-radius: 10px;
            margin-bottom: 12px;
        }
        .header h1 {
            font-size: 16px;
            margin-bottom: 5px;
        }
        .total-display {
            font-size: 28px;
            font-weight: bold;
        }
        .metodo-pago {
            margin-bottom: 10px;
            border: 2px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        .metodo-header {
            padding: 9px 12px;
            color: white;
            font-weight: bold;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .efectivo .metodo-header {
            background: #4caf50;
        }
        .sinpe .metodo-header {
            background: #2196f3;
        }
        .datafono .metodo-header {
            background: #ff9800;
        }
        .metodo-body {
            padding: 10px;
            background: white;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .metodo-body input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 22px;
            text-align: center;
        }
        .btn-completo {
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
        }
        .efectivo .btn-completo {
            background: #4caf50;
            color: white;
        }
        .sinpe .btn-completo {
            background: #2196f3;
            color: white;
        }
        .datafono .btn-completo {
            background: #ff9800;
            color: white;
        }
        .resumen-section {
            margin: 10px 0;
            padding: 10px;
            background: #f5f5f5;
            border-radius: 8px;
        }
        .resumen-titulo {
            font-weight: bold;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .resumen-item {
            padding: 8px;
            background: white;
            margin-bottom: 5px;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
        }
        .resumen-vacio {
            color: #999;
            font-style: italic;
            padding: 10px;
        }
        .totales-section {
            display: flex;
            justify-content: space-between;
            margin: 10px 0;
            gap: 10px;
        }
        .total-box {
            flex: 1;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
        }
        .vuelto-box {
            background: #e8f5e9;
            border: 2px solid #4caf50;
        }
        .pendiente-box {
            background: #ffebee;
            border: 2px solid #f44336;
        }
        .total-box-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        .total-box-monto {
            font-size: 20px;
            font-weight: bold;
        }
        .vuelto-box .total-box-monto {
            color: #4caf50;
        }
        .pendiente-box .total-box-monto {
            color: #f44336;
        }
        .btn-confirmar {
            width: 100%;
            padding: 13px;
            background: #4caf50;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }
        .btn-confirmar:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .btn-cancelar {
            width: 100%;
            padding: 12px;
            background: #666;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💳 Formas de Pago</h1>
            <div>Orden #<?php echo $orden['numero_orden']; ?> - <?php echo htmlspecialchars($orden['nombre_cliente']); ?></div>
            <div class="total-display">₡<?php echo number_format($orden['total'], 0); ?></div>
        </div>

        <!-- Efectivo -->
        <div class="metodo-pago efectivo">
            <div class="metodo-header">
                💵 EFECTIVO
            </div>
            <div class="metodo-body">
                <input type="text" id="monto_efectivo" placeholder="0" inputmode="numeric" autocomplete="off">
                <button class="btn-completo" onclick="completoEfectivo()">Completo</button>
            </div>
        </div>

        <!-- SINPE -->
        <div class="metodo-pago sinpe">
            <div class="metodo-header">
                📌 SINPE
            </div>
            <div class="metodo-body">
                <input type="text" id="monto_sinpe" placeholder="0" inputmode="numeric" autocomplete="off">
                <button class="btn-completo" onclick="completoSinpe()">Completo</button>
            </div>
        </div>

        <!-- Datáfono -->
        <div class="metodo-pago datafono">
            <div class="metodo-header">
                🧾 DATÁFONO
            </div>
            <div class="metodo-body">
                <input type="text" id="monto_datafono" placeholder="0" inputmode="numeric" autocomplete="off">
                <button class="btn-completo" onclick="completoDatafono()">Completo</button>
            </div>
        </div>

        <!-- Resumen de Pagos -->
        <div class="resumen-section">
            <div class="resumen-titulo">📋 Resumen de Pagos</div>
            <div id="resumen_pagos">
                <div class="resumen-vacio">Ninguna forma de pago seleccionada</div>
            </div>
        </div>

        <!-- Vuelto y Pendiente -->
        <div class="totales-section">
            <div class="total-box vuelto-box">
                <div class="total-box-label">💰 Vuelto</div>
                <div class="total-box-monto" id="display_vuelto">₡0</div>
            </div>
            <div class="total-box pendiente-box">
                <div class="total-box-label">Pendiente</div>
                <div class="total-box-monto" id="display_pendiente">₡<?php echo number_format($pendiente_inicial, 0); ?></div>
            </div>
        </div>

        <div id="aviso_monto" style="display:none;background:#fff3e0;border:2px solid #ff9800;color:#e65100;
             border-radius:8px;padding:12px;margin-bottom:12px;font-size:14px;font-weight:bold;text-align:center"></div>

        <button class="btn-confirmar" id="btn_confirmar" disabled onclick="confirmarPago()">
            Confirmar Pago
        </button>

        <button class="btn-cancelar" onclick="window.location.href='ordenes_activas.php'">
            Cancelar
        </button>
    </div>

    <script>
        const TOTAL_ORDEN = <?php echo $orden['total']; ?>;
        const ORDEN_ID = <?php echo $orden_id; ?>;
        
        let pagos = {
            efectivo: 0,
            sinpe: 0,
            tarjeta: 0
        };

        function completoEfectivo() {
            let pendiente = calcularPendiente();
            document.getElementById('monto_efectivo').value = Math.max(0, pendiente);
            actualizarResumen();
        }

        function completoSinpe() {
            let pendiente = calcularPendiente();
            document.getElementById('monto_sinpe').value = Math.max(0, pendiente);
            actualizarResumen();
        }

        function completoDatafono() {
            let pendiente = calcularPendiente();
            document.getElementById('monto_datafono').value = Math.max(0, pendiente);
            actualizarResumen();
        }

        function calcularPendiente() {
            let efectivo = parseFloat(document.getElementById('monto_efectivo').value) || 0;
            let sinpe = parseFloat(document.getElementById('monto_sinpe').value) || 0;
            let tarjeta = parseFloat(document.getElementById('monto_datafono').value) || 0;
            
            let total_pagado = efectivo + sinpe + tarjeta;
            return Math.max(0, TOTAL_ORDEN - total_pagado);
        }

        function calcularVuelto() {
            let efectivo = parseFloat(document.getElementById('monto_efectivo').value) || 0;
            let sinpe = parseFloat(document.getElementById('monto_sinpe').value) || 0;
            let tarjeta = parseFloat(document.getElementById('monto_datafono').value) || 0;
            
            let total_pagado = efectivo + sinpe + tarjeta;
            return Math.max(0, total_pagado - TOTAL_ORDEN);
        }

        function actualizarResumen() {
            let efectivo = parseFloat(document.getElementById('monto_efectivo').value) || 0;
            let sinpe = parseFloat(document.getElementById('monto_sinpe').value) || 0;
            let tarjeta = parseFloat(document.getElementById('monto_datafono').value) || 0;
            
            pagos = {efectivo, sinpe, tarjeta};
            
            let resumenHTML = '';
            let hayPagos = false;
            
            if(efectivo > 0) {
                resumenHTML += `<div class="resumen-item"><span>💵 Efectivo</span><span>₡${efectivo.toLocaleString('es-CR')}</span></div>`;
                hayPagos = true;
            }
            if(sinpe > 0) {
                resumenHTML += `<div class="resumen-item"><span>📌 SINPE</span><span>₡${sinpe.toLocaleString('es-CR')}</span></div>`;
                hayPagos = true;
            }
            if(tarjeta > 0) {
                resumenHTML += `<div class="resumen-item"><span>🧾 Datáfono</span><span>₡${tarjeta.toLocaleString('es-CR')}</span></div>`;
                hayPagos = true;
            }
            
            if(!hayPagos) {
                resumenHTML = '<div class="resumen-vacio">Ninguna forma de pago seleccionada</div>';
            }
            
            document.getElementById('resumen_pagos').innerHTML = resumenHTML;
            
            // Actualizar vuelto y pendiente
            let vuelto = calcularVuelto();
            let pendiente = calcularPendiente();
            
            document.getElementById('display_vuelto').textContent = '₡' + vuelto.toLocaleString('es-CR');
            document.getElementById('display_pendiente').textContent = '₡' + pendiente.toLocaleString('es-CR');

            // Aviso visual si el vuelto es imposible (nadie paga con más de ₡50.000)
            const avisoBox = document.getElementById('aviso_monto');
            if(vuelto > VUELTO_SOSPECHOSO) {
                avisoBox.style.display = 'block';
                avisoBox.textContent = '⚠️ El vuelto sería ₡' + vuelto.toLocaleString('es-CR') +
                                       '. Revisá si se te fue un cero de más.';
            } else {
                avisoBox.style.display = 'none';
            }

            // Habilitar/deshabilitar botón confirmar
            document.getElementById('btn_confirmar').disabled = (pendiente > 0);
        }

        // Ningún billete pasa de ₡50.000, así que un vuelto mayor es casi seguro un error de tecleo
        const VUELTO_SOSPECHOSO = 50000;

        function confirmarPago() {
            if(calcularPendiente() > 0) {
                mostrarNotificacion('Aún hay monto pendiente por pagar', 'error');
                return;
            }

            const vuelto = calcularVuelto();
            if(vuelto > VUELTO_SOSPECHOSO) {
                const recibido = pagos.efectivo + pagos.sinpe + pagos.tarjeta;
                const ok = confirm(
                    'Revisá los montos antes de cobrar:\n\n' +
                    'La orden es de ₡' + TOTAL_ORDEN.toLocaleString('es-CR') + '\n' +
                    'Estás registrando ₡' + recibido.toLocaleString('es-CR') + '\n' +
                    'Vuelto: ₡' + vuelto.toLocaleString('es-CR') + '\n\n' +
                    '¿Está bien así?'
                );
                if(!ok) return;
            }

            // Enviar al servidor
            fetch('procesar_pago.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    orden_id: ORDEN_ID,
                    pagos: pagos,
                    vuelto: calcularVuelto()
                })
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    mostrarNotificacion('Pago registrado exitosamente\nVuelto: ₡' + calcularVuelto().toLocaleString('es-CR'));
                    setTimeout(() => window.location.href = 'ordenes_activas.php', 800);
                } else {
                    mostrarNotificacion('Error al procesar el pago: ' + data.error, 'error');
                }
            })
            .catch(error => {
                mostrarNotificacion('Error al procesar el pago', 'error');
                console.error(error);
            });
        }

        // Actualizar resumen al cambiar valores manualmente
        document.getElementById('monto_efectivo').addEventListener('input', actualizarResumen);
        document.getElementById('monto_sinpe').addEventListener('input', actualizarResumen);
        document.getElementById('monto_datafono').addEventListener('input', actualizarResumen);
    </script>
</body>
</html>


