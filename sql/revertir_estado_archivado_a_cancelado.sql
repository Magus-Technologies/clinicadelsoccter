-- =====================================================================
-- revertir_estado_archivado_a_cancelado.sql
--
-- Devuelve a 'cancelado' las OTs que la migracion migracion_ot_venta.sql
-- habia movido a 'archivado'.
--
-- Alcance: SOLO las 355 OTs que movio esa migracion, identificadas por el
-- rastro que ella misma dejo en `historial_ot`. Las 3 OTs que ya estaban
-- en 'archivado' antes NO se tocan.
--
-- Deja constancia del cambio en `historial_ot`, igual que la migracion.
--
-- ---------------------------------------------------------------------
-- COMO CORRER
--   mysqldump -u<user> -p --single-transaction r_scooter > backup_pre_revert.sql
--   mysql -u<user> -p r_scooter < sql/revertir_estado_archivado_a_cancelado.sql
-- ---------------------------------------------------------------------
-- =====================================================================

-- Conjunto exacto a revertir: en 'archivado' Y movidas por la migracion.
DROP TEMPORARY TABLE IF EXISTS _rev_ots;
CREATE TEMPORARY TABLE _rev_ots (
    ot_id INT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

INSERT INTO _rev_ots (ot_id)
SELECT DISTINCT o.id
FROM `ordenes_trabajo` o
JOIN `historial_ot` h ON h.ot_id = o.id
WHERE o.estado = 'archivado'
  AND h.comentario LIKE 'Correccion automatica de migracion%';

-- Rastro del cambio, antes de aplicarlo.
INSERT INTO `historial_ot` (ot_id, usuario_id, estado_antes, estado_nuevo, comentario, created_at)
SELECT o.id,
       o.usuario_creador_id,
       'archivado',
       'cancelado',
       'Revertido a cancelado a pedido de operaciones: el negocio usa "cancelado" para la OT ya boleteada y cobrada.',
       NOW()
FROM `ordenes_trabajo` o
JOIN _rev_ots r ON r.ot_id = o.id;

UPDATE `ordenes_trabajo` o
JOIN _rev_ots r ON r.ot_id = o.id
SET o.estado = 'cancelado';

-- =====================================================================
-- VERIFICACION
-- =====================================================================
SELECT 'OTs revertidas a cancelado' AS control, COUNT(*) AS valor FROM _rev_ots
UNION ALL
SELECT 'Quedan en archivado (no se tocaron)', COUNT(*) FROM `ordenes_trabajo` WHERE estado = 'archivado'
UNION ALL
SELECT 'Total en cancelado', COUNT(*) FROM `ordenes_trabajo` WHERE estado = 'cancelado'
UNION ALL
SELECT 'OTs activas en el dashboard', COUNT(*)
FROM `ordenes_trabajo` o
WHERE o.estado NOT IN (SELECT clave FROM `estados_ot` WHERE es_final = 1);

DROP TEMPORARY TABLE IF EXISTS _rev_ots;
