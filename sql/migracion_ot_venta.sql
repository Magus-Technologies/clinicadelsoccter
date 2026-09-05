-- =====================================================================
-- migracion_ot_venta.sql
--
-- Unifica Orden de Trabajo y Venta en una sola transaccion contable.
--
-- Contexto del problema:
--   * `ventas` y `ordenes_trabajo` no tienen ninguna relacion en el schema.
--   * `venta_detalle.producto_id` es NOT NULL con FK a `productos`, por lo
--     que una OT (que no es un producto) no puede ser una linea de venta.
--   * El vinculo OT <-> venta vive hoy en `ventas.notas` como texto plano
--     con el formato ##OT##<codigo> - <desc>##PRECIO##<monto>##FIN##.
--   * Los reportes suman `ventas.total` + `ordenes_trabajo.precio_final`,
--     contando la misma plata dos veces.
--
-- Fuente de verdad acordada: `ventas.total`. `ordenes_trabajo.precio_final`
-- pasa a ser presupuesto, no ingreso.
--
-- La migracion es idempotente: se puede correr mas de una vez sin efecto.
-- Compatible con MySQL 8 y MariaDB (no usa ADD COLUMN IF NOT EXISTS).
--
-- ---------------------------------------------------------------------
-- COMO CORRER
--
--   1) Backup obligatorio, antes de nada:
--        mysqldump -u<user> -p --single-transaction --routines r_scooter \
--          > backup_pre_migracion.sql
--
--   2) Aplicar:
--        mysql -u<user> -p r_scooter < sql/migracion_ot_venta.sql
--
--   3) Leer el bloque de VERIFICACION que imprime al final.
--      "Ingreso real" y "Lineas - descuentos" deben coincidir, salvo por
--      el monto de las ventas huerfanas que el mismo bloque lista.
--
--   4) Recien despues subir los cambios de PHP. El codigo nuevo asume
--      que existen las columnas venta_detalle.ot_id y
--      ot_repuestos.stock_descontado.
--
-- Para volver atras: restaurar el backup del paso 1.
-- ---------------------------------------------------------------------
-- =====================================================================

-- ---------------------------------------------------------------------
-- Helper: agrega una columna solo si no existe todavia.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _mig_add_column;
DELIMITER //
CREATE PROCEDURE _mig_add_column(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_ddl    TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_column
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//

-- ---------------------------------------------------------------------
-- Helper: agrega un indice solo si no existe todavia.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _mig_add_index//
CREATE PROCEDURE _mig_add_index(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_cols  VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND INDEX_NAME   = p_index
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_cols, ')');
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//
DELIMITER ;

-- =====================================================================
-- 1. SCHEMA
-- =====================================================================

-- 1.1 Una linea de venta puede ser un servicio/OT sin producto asociado.
--     La capa SUNAT ya tolera esto: SunatService::fetchItems() hace
--     LEFT JOIN productos y SunatBuilder lee `concepto` antes que `nombre`.
ALTER TABLE `venta_detalle` MODIFY COLUMN `producto_id` INT UNSIGNED NULL;

-- 1.2 El vinculo real OT <-> venta, a nivel de linea.
--     Va en venta_detalle (no en ventas) para soportar una boleta que
--     agrupe varias OTs en el futuro.
CALL _mig_add_column('venta_detalle', 'ot_id',
    '`ot_id` INT UNSIGNED NULL DEFAULT NULL COMMENT ''OT que origina esta linea'' AFTER `producto_id`');

CALL _mig_add_index('venta_detalle', 'idx_ot_id', '`ot_id`');

-- 1.3 Marca si el repuesto ya descontó stock, para no descontar dos veces
--     cuando una OT se edita o se reabre.
CALL _mig_add_column('ot_repuestos', 'stock_descontado',
    '`stock_descontado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = ya genero salida de kardex''');

CALL _mig_add_index('ot_repuestos', 'idx_producto_id', '`producto_id`');

-- 1.4 FK de venta_detalle.ot_id.
--     ON DELETE SET NULL: si se borra una OT, la linea de venta sobrevive
--     porque es un documento fiscal ya emitido.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'venta_detalle'
      AND CONSTRAINT_NAME = 'fk_venta_detalle_ot'
);
SET @ddl := IF(@fk_exists = 0,
    'ALTER TABLE `venta_detalle` ADD CONSTRAINT `fk_venta_detalle_ot` FOREIGN KEY (`ot_id`) REFERENCES `ordenes_trabajo`(`id`) ON DELETE SET NULL',
    'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 1.5 `archivado` (label "Finalizado") es el cierre normal de una OT.
--     Hoy el unico estado con es_final=1 es `cancelado`, y por eso el
--     equipo marcaba OTs cobradas como canceladas para sacarlas del POS.
UPDATE `estados_ot` SET `es_final` = 1 WHERE `clave` = 'archivado';

-- =====================================================================
-- 2. BACKFILL — reconstruir el vinculo OT <-> venta
-- =====================================================================

-- 2.1 Tabla temporal con los pares detectados desde `ventas.notas`.
--
--     Entre las ventas COMPLETADAS la relacion es 1:1: ninguna agrupa dos
--     OTs, y cada OT aparece en una sola venta. Hay 2 OTs que figuran en
--     3 ventas cada una, pero las 6 estan anuladas (pruebas de mayo 2026);
--     se les crea igual la linea para documentar que se anularon, y los
--     pasos 2.3 y 2.4 las ignoran porque filtran por estado 'completada'.
DROP TEMPORARY TABLE IF EXISTS _mig_pares;
CREATE TEMPORARY TABLE _mig_pares (
    venta_id    INT UNSIGNED  NOT NULL,
    ot_id       INT UNSIGNED  NOT NULL,
    codigo_ot   VARCHAR(20)   NOT NULL,
    concepto    VARCHAR(255)  NOT NULL,
    monto       DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (venta_id, ot_id)
) ENGINE=InnoDB;

INSERT INTO _mig_pares (venta_id, ot_id, codigo_ot, concepto, monto)
SELECT
    v.id,
    o.id,
    o.codigo_ot,
    -- Texto entre ##OT## y ##PRECIO##; si no se puede extraer, se arma uno.
    LEFT(
      TRIM(COALESCE(
        NULLIF(
          SUBSTRING_INDEX(SUBSTRING_INDEX(v.notas, '##PRECIO##', 1), '##OT##', -1),
        ''),
        CONCAT('Servicio tecnico ', o.codigo_ot)
      )), 255
    ),
    -- La boleta es la fuente de verdad del monto.
    v.total
FROM `ventas` v
JOIN `ordenes_trabajo` o
  ON v.notas LIKE CONCAT('%', o.codigo_ot, '%')
WHERE v.notas LIKE '%##OT##%';

-- 2.2 Materializar el vinculo como linea de venta.
--
--     Hay dos situaciones distintas y mezclarlas duplica plata:
--
--     (a) La venta no tiene ninguna linea (el caso normal, 354 de 355):
--         toda la plata de la boleta corresponde a la OT, asi que se
--         crea una linea nueva por el residual, que es el total.
--
--     (b) La venta YA tiene lineas que cubren el total: el operador
--         "desagrego" la OT a mano y la plata ya esta contabilizada.
--         Crear otra linea la sumaria dos veces. Ahi solo se le pega el
--         ot_id a la linea existente para que el vinculo exista.

-- Residual de cada venta = total del comprobante - lo ya facturado en lineas.
DROP TEMPORARY TABLE IF EXISTS _mig_residual;
CREATE TEMPORARY TABLE _mig_residual (
    venta_id INT UNSIGNED NOT NULL PRIMARY KEY,
    residual DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB;

INSERT INTO _mig_residual (venta_id, residual)
SELECT p.venta_id,
       v.total - COALESCE((SELECT SUM(d.subtotal) FROM `venta_detalle` d WHERE d.venta_id = v.id), 0)
FROM (SELECT DISTINCT venta_id FROM _mig_pares) p
JOIN `ventas` v ON v.id = p.venta_id;

-- (a) Ventas cuyo importe todavia no esta en ninguna linea.
INSERT INTO `venta_detalle` (venta_id, producto_id, ot_id, concepto, cantidad, precio_unit, descuento, subtotal)
SELECT p.venta_id, NULL, p.ot_id, p.concepto, 1, r.residual, 0.00, r.residual
FROM _mig_pares p
JOIN _mig_residual r ON r.venta_id = p.venta_id
WHERE r.residual > 0
  AND NOT EXISTS (
      SELECT 1 FROM `venta_detalle` d
      WHERE d.venta_id = p.venta_id AND d.ot_id = p.ot_id
  );

-- (b) Ventas ya desagregadas a mano: se etiqueta la linea existente.
UPDATE `venta_detalle` d
JOIN _mig_pares    p ON p.venta_id = d.venta_id
JOIN _mig_residual r ON r.venta_id = d.venta_id
SET d.ot_id = p.ot_id
WHERE r.residual <= 0
  AND d.ot_id IS NULL;

-- 2.3 Sincronizar el estado de pago de la OT con la venta real.
UPDATE `ordenes_trabajo` o
JOIN _mig_pares p ON p.ot_id = o.id
JOIN `ventas`    v ON v.id   = p.venta_id
SET o.pagado      = 1,
    o.fecha_pago  = COALESCE(o.fecha_pago, v.created_at),
    o.metodo_pago = COALESCE(o.metodo_pago,
                             CASE WHEN v.metodo_pago = 'mixto' THEN 'efectivo' ELSE v.metodo_pago END)
WHERE v.estado = 'completada';

-- 2.4 Corregir el estado de las OTs que fueron marcadas `cancelado`
--     unicamente para sacarlas del buscador del POS.
--     Solo se tocan las que tienen una venta completada asociada.
--     Se deja rastro en historial_ot para no repetir el error de editar.php.
INSERT INTO `historial_ot` (ot_id, usuario_id, estado_antes, estado_nuevo, comentario, created_at)
SELECT o.id,
       o.usuario_creador_id,
       o.estado,
       'archivado',
       CONCAT('Correccion automatica de migracion: la OT fue facturada en ', v.codigo,
              '. Estaba marcada como "', o.estado, '" solo para ocultarla del POS.'),
       NOW()
FROM `ordenes_trabajo` o
JOIN _mig_pares p ON p.ot_id = o.id
JOIN `ventas`    v ON v.id   = p.venta_id
WHERE v.estado = 'completada'
  AND o.estado = 'cancelado';

UPDATE `ordenes_trabajo` o
JOIN _mig_pares p ON p.ot_id = o.id
JOIN `ventas`    v ON v.id   = p.venta_id
SET o.estado        = 'archivado',
    o.fecha_entrega = COALESCE(o.fecha_entrega, v.created_at)
WHERE v.estado = 'completada'
  AND o.estado = 'cancelado';

-- 2.5 Recuperar el vinculo repuesto -> producto donde sea deducible.
--     (a) por codigo entre corchetes, ej: Camara 10 pulg [PRD-00018]
UPDATE `ot_repuestos` r
JOIN `productos` p ON r.descripcion LIKE CONCAT('%[', p.codigo, ']%')
SET r.producto_id = p.id
WHERE r.producto_id IS NULL;

--     (b) por nombre exacto
UPDATE `ot_repuestos` r
JOIN `productos` p ON p.nombre = r.descripcion
SET r.producto_id = p.id
WHERE r.producto_id IS NULL;

-- NOTA: el stock historico NO se descuenta aca a proposito.
-- Descontar ~S/ 93.000 de repuestos retroactivos dejaria el stock en
-- negativo y sin contraparte fisica. El flag stock_descontado queda en 0
-- para todo lo historico; el descuento automatico aplica solo de aca en
-- adelante. El ajuste del inventario fisico se hace por toma de inventario.

-- =====================================================================
-- 3. VERIFICACION
-- =====================================================================
SELECT 'Lineas de venta vinculadas a una OT' AS control, COUNT(*) AS valor
FROM `venta_detalle` WHERE ot_id IS NOT NULL
UNION ALL
SELECT 'Ingreso real (unica fuente: ventas)', ROUND(SUM(total),2)
FROM `ventas` WHERE estado='completada'
UNION ALL
-- Reconciliacion POR VENTA. No se puede comparar en bloque restando
-- `descuento`: las lineas de producto son pre-descuento, pero las lineas
-- de OT que crea esta migracion usan `ventas.total`, que ya viene neto.
-- Por eso se acepta cualquiera de las dos formas y se cuentan las que no
-- cierran de ninguna. Ese es el numero que tiene que dar 0.
SELECT 'Ventas que NO reconcilian (debe ser 0)', COUNT(*)
FROM `ventas` v
JOIN (SELECT venta_id, SUM(subtotal) lineas FROM `venta_detalle` GROUP BY venta_id) x
  ON x.venta_id = v.id
WHERE v.estado='completada'
  AND ABS(x.lineas - v.total) >= 0.01
  AND ABS(x.lineas - v.descuento - v.total) >= 0.01
UNION ALL
-- Ventas viejas de prueba, sin lineas y sin referencia a ninguna OT.
-- No las inventa la migracion: ya estaban asi. Se listan para que el
-- descuadre quede explicado y no parezca un error del backfill.
SELECT 'Ventas huerfanas preexistentes (revisar o anular)', COUNT(*)
FROM `ventas` v WHERE v.estado='completada'
  AND NOT EXISTS (SELECT 1 FROM `venta_detalle` d WHERE d.venta_id = v.id)
UNION ALL
SELECT 'Monto de esas huerfanas', ROUND(COALESCE(SUM(v.total),0),2)
FROM `ventas` v WHERE v.estado='completada'
  AND NOT EXISTS (SELECT 1 FROM `venta_detalle` d WHERE d.venta_id = v.id)
UNION ALL
SELECT 'OTs aun en cancelado', COUNT(*) FROM `ordenes_trabajo` WHERE estado='cancelado'
UNION ALL
SELECT 'Repuestos con producto_id recuperado', COUNT(*)
FROM `ot_repuestos` WHERE producto_id IS NOT NULL
UNION ALL
SELECT 'Repuestos aun sin vincular (revision manual)', COUNT(*)
FROM `ot_repuestos` WHERE producto_id IS NULL;

DROP TEMPORARY TABLE IF EXISTS _mig_pares;
DROP TEMPORARY TABLE IF EXISTS _mig_residual;
DROP PROCEDURE IF EXISTS _mig_add_column;
DROP PROCEDURE IF EXISTS _mig_add_index;
