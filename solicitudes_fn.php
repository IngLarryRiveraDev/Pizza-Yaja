<?php
// Solicitudes que el personal manda al ERP y Yaja aprueba o rechaza.
// Sirve para eliminar una comanda y para habilitar el cierre de caja.

function setupSolicitudes($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS solicitudes (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            tipo           ENUM('eliminacion','cierre') NOT NULL,
            estado         ENUM('pendiente','aprobada','rechazada','usada') NOT NULL DEFAULT 'pendiente',
            orden_id       INT NULL,
            numero_orden   INT NULL,
            usuario_id     INT NOT NULL,
            sucursal       VARCHAR(20) NOT NULL DEFAULT 'cariari',
            motivo         TEXT,
            respuesta      TEXT,
            respondido_por INT NULL,
            respondido_at  DATETIME NULL,
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_estado (tipo, estado, sucursal)
        )
    ");
}

// Solicitud de eliminación pendiente de una orden (si existe)
function solicitudEliminacionPendiente($conn, $orden_id) {
    setupSolicitudes($conn);
    $stmt = $conn->prepare("
        SELECT * FROM solicitudes
        WHERE tipo = 'eliminacion' AND estado = 'pendiente' AND orden_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orden_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Corta cualquier acción sobre una orden que está esperando respuesta.
// Devuelve JSON y termina; usar en los endpoints que modifican la orden.
function bloquearSiPendiente($conn, $orden_id) {
    if(solicitudEliminacionPendiente($conn, $orden_id)) {
        echo json_encode([
            'success' => false,
            'error'   => 'Esta orden está esperando que la administración apruebe su eliminación'
        ]);
        exit;
    }
}

// Ids de órdenes bloqueadas en una sucursal, para pintarlas en la lista
function ordenesBloqueadas($conn, $sucursal = null) {
    setupSolicitudes($conn);
    if($sucursal) {
        $stmt = $conn->prepare("SELECT orden_id, motivo FROM solicitudes WHERE tipo='eliminacion' AND estado='pendiente' AND sucursal = ?");
        $stmt->execute([$sucursal]);
    } else {
        $stmt = $conn->query("SELECT orden_id, motivo FROM solicitudes WHERE tipo='eliminacion' AND estado='pendiente'");
    }
    $out = [];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['orden_id']] = $r['motivo'];
    return $out;
}

// Borra la orden de verdad y deja el registro en el log. Se llama al aprobar.
function eliminarOrdenAprobada($conn, $orden_id, $motivo, $eliminado_por) {
    $stmt = $conn->prepare("SELECT * FROM ordenes WHERE id = ?");
    $stmt->execute([$orden_id]);
    $orden = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$orden) return false;

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

    $conn->prepare("
        INSERT INTO log_eliminaciones (orden_id, numero_orden, nombre_cliente, total, motivo, eliminado_por, sucursal)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([
        $orden_id, $orden['numero_orden'], $orden['nombre_cliente'], $orden['total'],
        $motivo, $eliminado_por, $orden['sucursal'] ?? null
    ]);

    $conn->prepare("DELETE FROM pagos         WHERE orden_id = ?")->execute([$orden_id]);
    $conn->prepare("DELETE FROM detalle_orden WHERE orden_id = ?")->execute([$orden_id]);
    $conn->prepare("DELETE FROM ordenes       WHERE id = ?")->execute([$orden_id]);
    return true;
}
