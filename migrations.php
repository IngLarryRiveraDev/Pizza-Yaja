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

// Cambios de datos que deben aplicarse UNA sola vez (por ejemplo, crear un
// producto). Quedan anotados en la tabla migraciones_datos: si después lo
// borran o lo cambian desde el ERP, no se vuelve a crear.
function migracionUnaVez($conn, $clave, callable $aplicar) {
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS migraciones_datos (
            clave       VARCHAR(100) PRIMARY KEY,
            aplicada_en DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $ya = $conn->prepare("SELECT 1 FROM migraciones_datos WHERE clave = ?");
        $ya->execute([$clave]);
        if($ya->fetchColumn()) return;

        if(!$conn->query("SELECT GET_LOCK(" . $conn->quote('pizzayaja_' . $clave) . ", 5)")->fetchColumn()) return;
        try {
            $ya->execute([$clave]);
            if($ya->fetchColumn()) return;
            $conn->beginTransaction();
            $aplicar($conn);
            $conn->prepare("INSERT INTO migraciones_datos (clave) VALUES (?)")->execute([$clave]);
            $conn->commit();
        } finally {
            if($conn->inTransaction()) $conn->rollBack();
            $conn->query("SELECT RELEASE_LOCK(" . $conn->quote('pizzayaja_' . $clave) . ")");
        }
    } catch(PDOException $e) {}
}

// Productos nuevos que se agregan desde el código (se aplican una sola vez)
function setupProductosNuevos($conn) {
    // Calzone de Birria pequeño ₡3.500 (en el POS sale como "Calzone Birria (pequeño)")
    migracionUnaVez($conn, 'calzone_birria_pequeno', function($conn) {
        $existe = $conn->query("SELECT 1 FROM productos WHERE categoria_id = 5 AND nombre LIKE '%Birria%' LIMIT 1")->fetchColumn();
        if($existe) return; // ya lo habían creado a mano desde el ERP
        $catTexto = $conn->query("SELECT categoria FROM productos WHERE categoria_id = 5 LIMIT 1")->fetchColumn() ?: 'Calzone';
        $conn->prepare("
            INSERT INTO productos (nombre, descripcion, precio, categoria_id, categoria, es_premium, precio_premium, activo, disponible)
            VALUES ('Birria (pequeño)', 'Calzone pequeño de birria', 3500, 5, ?, 0, 0, 1, 1)
        ")->execute([$catTexto]);
    });
}

// Sushi: categoría nueva con sus productos iniciales. Los productos se crean
// UNA sola vez, junto con la categoría; después se administran desde el ERP,
// así que si borran o cambian uno, esta migración no lo vuelve a crear.
function setupSushi($conn) {
    try {
        if($conn->query("SELECT 1 FROM categorias WHERE nombre = 'Sushi' LIMIT 1")->fetchColumn()) return;

        // Candado: si dos personas abren el sistema al mismo tiempo, solo una crea la categoría
        if(!$conn->query("SELECT GET_LOCK('pizzayaja_setup_sushi', 5)")->fetchColumn()) return;
        try {
            if($conn->query("SELECT 1 FROM categorias WHERE nombre = 'Sushi' LIMIT 1")->fetchColumn()) return;

            $conn->beginTransaction();
            $orden = (int)$conn->query("SELECT COALESCE(MAX(orden), 0) + 1 FROM categorias")->fetchColumn();
            $conn->prepare("INSERT INTO categorias (nombre, orden) VALUES ('Sushi', ?)")->execute([$orden]);
            $catId = (int)$conn->lastInsertId();

            $ins = $conn->prepare("
                INSERT INTO productos (nombre, descripcion, precio, categoria_id, categoria, es_premium, precio_premium, activo, disponible)
                VALUES (?, ?, ?, ?, 'Sushi', 0, 0, 1, 1)
            ");
            $ins->execute(['Sushi',          '',                   6500, $catId]);
            $ins->execute(['Promo Sushi 4n', '',                   4500, $catId]);
            $ins->execute(['Extra Tempura',  'Se agrega al sushi', 1000, $catId]);
            $conn->commit();
        } finally {
            if($conn->inTransaction()) $conn->rollBack();
            $conn->query("SELECT RELEASE_LOCK('pizzayaja_setup_sushi')");
        }
    } catch(PDOException $e) {}
}
