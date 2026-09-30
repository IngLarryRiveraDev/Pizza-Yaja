<?php
function setupSucursalColumns($conn) {
    try {
        $conn->exec("ALTER TABLE usuarios ADD COLUMN sucursal VARCHAR(20) NOT NULL DEFAULT 'cariari'");
    } catch(PDOException $e) {}
    try {
        $conn->exec("ALTER TABLE ordenes ADD COLUMN sucursal VARCHAR(20) NOT NULL DEFAULT 'cariari'");
    } catch(PDOException $e) {}
}

// El avance de cocina va aparte del estado de pago: cobrar no debe sacar la
// comanda de la pantalla de cocina. 0 = sin enviar, 1 = en cocina, 2 = lista
function setupCocinaColumns($conn) {
    try {
        $conn->exec("ALTER TABLE ordenes ADD COLUMN cocina_estado TINYINT(1) NOT NULL DEFAULT 0");
        // Arrastrar el estado de las órdenes que ya existían
        $conn->exec("UPDATE ordenes SET cocina_estado = 1 WHERE estado = 'en_cocina'");
        $conn->exec("UPDATE ordenes SET cocina_estado = 2 WHERE estado IN ('listo','pagado','completado')");
    } catch(PDOException $e) {}
}

function setupProductosColumns($conn) {
    try {
        $conn->exec("ALTER TABLE productos ADD COLUMN disponible TINYINT(1) NOT NULL DEFAULT 1");
    } catch(PDOException $e) {}
}
