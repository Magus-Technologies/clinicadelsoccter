<?php
// includes/ventas.php — Registro de líneas de venta y cierre de las OTs cobradas
//
// El POS tiene dos caminos de emisión (SUNAT para boleta/factura, y el
// camino general para ticket/nota de venta). Ambos escribían las líneas
// y el cierre de OT por separado, y se fueron desincronizando: el camino
// SUNAT nunca llegó a marcar la OT como pagada. Estas funciones son la
// única implementación para los dos.

require_once __DIR__ . '/stock_ot.php';

/**
 * Estado con el que se cierra una OT cobrada.
 *
 * Se lee de `estados_ot` para respetar la configuración del negocio.
 * `cancelado` se excluye a propósito: cobrar una OT no es cancelarla.
 */
function estadoCierreOT(PDO $db): string {
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $st = $db->query("
            SELECT clave FROM estados_ot
            WHERE es_final = 1 AND activo = 1 AND clave <> 'cancelado'
            ORDER BY orden ASC LIMIT 1
        ");
        $cache = $st->fetchColumn() ?: 'archivado';
    } catch (Throwable $e) {
        $cache = 'archivado';
    }
    return $cache;
}

/**
 * `ordenes_trabajo.metodo_pago` no acepta 'mixto', que sí existe en `ventas`.
 */
function metodoPagoOT(string $metodoVenta): string {
    $validos = ['efectivo', 'yape', 'plin', 'tarjeta', 'transferencia'];
    return in_array($metodoVenta, $validos, true) ? $metodoVenta : 'efectivo';
}

/**
 * Inserta las líneas de una venta y mueve el stock de los productos.
 *
 * Un item del carrito es una de dos cosas:
 *   - un producto de inventario  → producto_id, descuenta stock y escribe kardex
 *   - una orden de trabajo       → ot_id + concepto, sin movimiento de stock
 *     (los repuestos de la OT ya descontaron stock en el taller)
 *
 * @param array $items Items del carrito tal como los manda el POS.
 */
function registrarLineasVenta(PDO $db, int $ventaId, string $codigoVenta, array $items, int $usuarioId): void {
    $insLinea = $db->prepare("
        INSERT INTO venta_detalle (venta_id, producto_id, ot_id, concepto, cantidad, precio_unit, descuento, subtotal)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $selStock = $db->prepare("SELECT stock_actual FROM productos WHERE id = ?");
    $updStock = $db->prepare("UPDATE productos SET stock_actual = ? WHERE id = ?");
    $insKardex = $db->prepare("
        INSERT INTO kardex (producto_id, tipo, cantidad, stock_antes, stock_despues, precio_unit, motivo, referencia, usuario_id)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");

    foreach ($items as $item) {
        $cant   = (float)($item['cantidad'] ?? 1);
        $precio = (float)($item['precio']   ?? 0);
        $subt   = round($cant * $precio, 2);

        // ── Línea de orden de trabajo ──────────────────────────────
        if (!empty($item['es_ot'])) {
            $otId = (int)($item['ot_id'] ?? 0);
            if ($otId <= 0) continue;

            $concepto = trim((string)($item['nombre'] ?? ''));
            if ($concepto === '') $concepto = 'Servicio técnico';

            $insLinea->execute([$ventaId, null, $otId, mb_substr($concepto, 0, 255), $cant, $precio, 0.00, $subt]);
            continue;
        }

        // ── Línea de producto de inventario ────────────────────────
        $pid = (int)($item['id'] ?? 0);
        if ($pid <= 0) continue;

        $insLinea->execute([$ventaId, $pid, null, null, $cant, $precio, 0.00, $subt]);

        $selStock->execute([$pid]);
        $antes   = (float)$selStock->fetchColumn();
        $despues = $antes - $cant;
        $updStock->execute([$despues, $pid]);
        $insKardex->execute([$pid, 'salida', $cant, $antes, $despues, $precio, 'Venta', $codigoVenta, $usuarioId]);
    }
}

/**
 * Cierra las OTs cobradas en una venta: las marca pagadas, las lleva al
 * estado final de cierre y deja constancia en el historial.
 *
 * Antes esto se hacía a mano: el equipo marcaba la OT como `cancelado`
 * para sacarla del buscador del POS y escribía el código de la venta en
 * un comentario de texto libre.
 */
function cerrarOTsDeVenta(PDO $db, array $items, string $metodoPago, int $usuarioId, string $codigoVenta): void {
    $estadoFinal = estadoCierreOT($db);
    $metodoOT    = metodoPagoOT($metodoPago);

    $selOT = $db->prepare("SELECT estado, pagado FROM ordenes_trabajo WHERE id = ?");
    $updOT = $db->prepare("
        UPDATE ordenes_trabajo
        SET pagado = 1,
            fecha_pago = COALESCE(fecha_pago, NOW()),
            metodo_pago = ?,
            estado = ?,
            fecha_entrega = COALESCE(fecha_entrega, NOW())
        WHERE id = ?
    ");
    $insHist = $db->prepare("
        INSERT INTO historial_ot (ot_id, usuario_id, estado_antes, estado_nuevo, comentario)
        VALUES (?,?,?,?,?)
    ");

    foreach ($items as $item) {
        if (empty($item['es_ot'])) continue;
        $otId = (int)($item['ot_id'] ?? 0);
        if ($otId <= 0) continue;

        $selOT->execute([$otId]);
        $ot = $selOT->fetch();
        if (!$ot) continue;

        $updOT->execute([$metodoOT, $estadoFinal, $otId]);

        // Recién ahora los repuestos salen del inventario: la OT se cobró.
        descontarStockOT($db, $otId, $usuarioId);

        if ($ot['estado'] !== $estadoFinal) {
            $insHist->execute([
                $otId, $usuarioId, $ot['estado'], $estadoFinal,
                'Cobrada en ' . $codigoVenta,
            ]);
        }
    }
}

/**
 * Revierte el cierre de las OTs de una venta anulada.
 */
function reabrirOTsDeVenta(PDO $db, int $ventaId, int $usuarioId, string $codigoVenta): void {
    $st = $db->prepare("
        SELECT DISTINCT d.ot_id, o.estado
        FROM venta_detalle d
        JOIN ordenes_trabajo o ON o.id = d.ot_id
        WHERE d.venta_id = ? AND d.ot_id IS NOT NULL
    ");
    $st->execute([$ventaId]);
    $filas = $st->fetchAll();
    if (!$filas) return;

    $updOT = $db->prepare("
        UPDATE ordenes_trabajo
        SET pagado = 0, fecha_pago = NULL, metodo_pago = NULL
        WHERE id = ?
    ");
    $insHist = $db->prepare("
        INSERT INTO historial_ot (ot_id, usuario_id, estado_antes, estado_nuevo, comentario)
        VALUES (?,?,?,?,?)
    ");

    foreach ($filas as $f) {
        $updOT->execute([$f['ot_id']]);
        // Los repuestos vuelven al inventario junto con la OT.
        revertirStockOT($db, (int)$f['ot_id'], $usuarioId);
        $insHist->execute([
            $f['ot_id'], $usuarioId, $f['estado'], $f['estado'],
            'Venta ' . $codigoVenta . ' anulada — la OT vuelve a estar pendiente de pago',
        ]);
    }
}
