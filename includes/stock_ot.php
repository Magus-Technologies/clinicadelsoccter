<?php
// includes/stock_ot.php — Movimiento de inventario originado en una OT
//
// Los repuestos de una orden de trabajo nunca descontaban stock: se
// guardaban como texto libre y el kardex no registraba una sola salida
// de taller. Estas funciones cierran ese circuito.
//
// El descuento ocurre cuando la OT se cobra, no cuando se edita, para que
// un presupuesto en borrador no mueva inventario. El flag
// `ot_repuestos.stock_descontado` hace la operación idempotente.

/**
 * Descuenta del inventario los repuestos de una OT que todavía no lo hicieron.
 *
 * Solo afecta a repuestos vinculados a un producto real. Los que están
 * cargados como texto libre se ignoran: no hay nada que descontar.
 */
function descontarStockOT(PDO $db, int $otId, int $usuarioId): void {
    $codigo = $db->prepare("SELECT codigo_ot FROM ordenes_trabajo WHERE id = ?");
    $codigo->execute([$otId]);
    $codigoOT = (string)$codigo->fetchColumn();
    if ($codigoOT === '') return;

    $st = $db->prepare("
        SELECT id, producto_id, cantidad, precio_unit
        FROM ot_repuestos
        WHERE ot_id = ? AND producto_id IS NOT NULL AND stock_descontado = 0
    ");
    $st->execute([$otId]);
    $filas = $st->fetchAll();
    if (!$filas) return;

    $selStock  = $db->prepare("SELECT stock_actual FROM productos WHERE id = ?");
    $updStock  = $db->prepare("UPDATE productos SET stock_actual = ? WHERE id = ?");
    $insKardex = $db->prepare("
        INSERT INTO kardex (producto_id, tipo, cantidad, stock_antes, stock_despues, precio_unit, motivo, referencia, usuario_id)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $marcar = $db->prepare("UPDATE ot_repuestos SET stock_descontado = 1 WHERE id = ?");

    foreach ($filas as $f) {
        $pid  = (int)$f['producto_id'];
        $cant = (float)$f['cantidad'];

        $selStock->execute([$pid]);
        $antes = $selStock->fetchColumn();
        if ($antes === false) continue; // producto borrado

        $antes   = (float)$antes;
        $despues = $antes - $cant;

        $updStock->execute([$despues, $pid]);
        $insKardex->execute([
            $pid, 'salida', $cant, $antes, $despues, (float)$f['precio_unit'],
            'Repuesto usado en OT', $codigoOT, $usuarioId,
        ]);
        $marcar->execute([(int)$f['id']]);
    }
}

/**
 * Devuelve al inventario los repuestos de una OT que ya habían descontado.
 *
 * Se usa al anular la venta que cobró la OT, y antes de reescribir la
 * lista de repuestos desde el formulario de edición.
 */
function revertirStockOT(PDO $db, int $otId, int $usuarioId): void {
    $codigo = $db->prepare("SELECT codigo_ot FROM ordenes_trabajo WHERE id = ?");
    $codigo->execute([$otId]);
    $codigoOT = (string)$codigo->fetchColumn();
    if ($codigoOT === '') return;

    $st = $db->prepare("
        SELECT id, producto_id, cantidad, precio_unit
        FROM ot_repuestos
        WHERE ot_id = ? AND producto_id IS NOT NULL AND stock_descontado = 1
    ");
    $st->execute([$otId]);
    $filas = $st->fetchAll();
    if (!$filas) return;

    $selStock  = $db->prepare("SELECT stock_actual FROM productos WHERE id = ?");
    $updStock  = $db->prepare("UPDATE productos SET stock_actual = ? WHERE id = ?");
    $insKardex = $db->prepare("
        INSERT INTO kardex (producto_id, tipo, cantidad, stock_antes, stock_despues, precio_unit, motivo, referencia, usuario_id)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $marcar = $db->prepare("UPDATE ot_repuestos SET stock_descontado = 0 WHERE id = ?");

    foreach ($filas as $f) {
        $pid  = (int)$f['producto_id'];
        $cant = (float)$f['cantidad'];

        $selStock->execute([$pid]);
        $antes = $selStock->fetchColumn();
        if ($antes === false) continue;

        $antes   = (float)$antes;
        $despues = $antes + $cant;

        $updStock->execute([$despues, $pid]);
        $insKardex->execute([
            $pid, 'devolucion', $cant, $antes, $despues, (float)$f['precio_unit'],
            'Repuesto devuelto de OT', $codigoOT, $usuarioId,
        ]);
        $marcar->execute([(int)$f['id']]);
    }
}
