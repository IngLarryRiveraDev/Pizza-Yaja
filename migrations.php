<?php
function setupSucursalColumns($conn) {
    try {
        $conn->exec("ALTER TABLE usuarios ADD COLUMN sucursal VARCHAR(20) NOT NULL DEFAULT 'cariari'");
    } catch(PDOException $e) {}
    try {
        $conn->exec("ALTER TABLE ordenes ADD COLUMN sucursal VARCHAR(20) NOT NULL DEFAULT 'cariari'");
    } catch(PDOException $e) {}
}

function setupProductosColumns($conn) {
    try {
        $conn->exec("ALTER TABLE productos ADD COLUMN disponible TINYINT(1) NOT NULL DEFAULT 1");
    } catch(PDOException $e) {}
}
