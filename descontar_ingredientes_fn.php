<?php
function setupRecetasTables($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS recetas (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            producto_id    INT NOT NULL,
            ingrediente_id INT NOT NULL,
            cantidad       DECIMAL(10,4) NOT NULL,
            UNIQUE KEY uk_prod_ing (producto_id, ingrediente_id)
        )
    ");
    // Columna anti-doble-descuento en ordenes
    try {
        $conn->exec("ALTER TABLE ordenes ADD COLUMN ingredientes_descontados TINYINT(1) NOT NULL DEFAULT 0");
    } catch(PDOException $e) {}
}

function descontarIngredientes($conn, $orden_id) {
    // Garantizar que las tablas existen
    setupRecetasTables($conn);

    // Verificar que no se haya descontado ya
    $chk = $conn->prepare("SELECT ingredientes_descontados FROM ordenes WHERE id=?");
    $chk->execute([$orden_id]);
    $ord = $chk->fetch(PDO::FETCH_ASSOC);
    if(!$ord || $ord['ingredientes_descontados']) return;

    // Items de la orden
    $items = $conn->prepare("SELECT producto_nombre, cantidad FROM detalle_orden WHERE orden_id=?");
    $items->execute([$orden_id]);
    $detalles = $items->fetchAll(PDO::FETCH_ASSOC);

    $stmtProd   = $conn->prepare("SELECT id FROM productos WHERE nombre=? LIMIT 1");
    $stmtRec    = $conn->prepare("SELECT ingrediente_id, cantidad FROM recetas WHERE producto_id=?");
    $stmtStock  = $conn->prepare("SELECT stock_actual FROM ingredientes WHERE id=? AND activo=1");
    $stmtUpdate = $conn->prepare("UPDATE ingredientes SET stock_actual=? WHERE id=?");
    $stmtLog    = $conn->prepare("
        INSERT INTO movimientos_inventario (ingrediente_id,tipo,cantidad,stock_antes,stock_despues,nota)
        VALUES (?,?,?,?,?,?)
    ");

    foreach($detalles as $item) {
        $stmtProd->execute([$item['producto_nombre']]);
        $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);
        if(!$prod) continue;

        $stmtRec->execute([$prod['id']]);
        $receta = $stmtRec->fetchAll(PDO::FETCH_ASSOC);
        if(empty($receta)) continue;

        foreach($receta as $r) {
            $totalDescontar = round($r['cantidad'] * $item['cantidad'], 4);

            $stmtStock->execute([$r['ingrediente_id']]);
            $ingRow = $stmtStock->fetch(PDO::FETCH_ASSOC);
            if(!$ingRow) continue;

            $antes   = (float)$ingRow['stock_actual'];
            $despues = round(max(0, $antes - $totalDescontar), 4);
            $real    = round($antes - $despues, 4);

            $stmtUpdate->execute([$despues, $r['ingrediente_id']]);
            $stmtLog->execute([
                $r['ingrediente_id'], 'salida', $real, $antes, $despues,
                "Orden #{$orden_id} — {$item['producto_nombre']} x{$item['cantidad']}"
            ]);
        }
    }

    // Marcar como descontado para no repetir
    $conn->prepare("UPDATE ordenes SET ingredientes_descontados=1 WHERE id=?")->execute([$orden_id]);
}
?>
