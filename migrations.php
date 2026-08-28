<?php
function setupSucursalColumns($conn) {
    try {
        $conn->exec("ALTER TABLE usuarios ADD COLUMN sucursal VARCHAR(20) NOT NULL DEFAULT 'cariari'");
    } catch(PDOException $e) {}
    try {
        $conn->exec("ALTER TABLE ordenes ADD COLUMN sucursal VARCHAR(20) NOT NULL DEFAULT 'cariari'");
    } catch(PDOException $e) {}
}
