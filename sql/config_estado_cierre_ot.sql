-- =====================================================================
-- config_estado_cierre_ot.sql
--
-- Declara con que estado queda una OT cuando se cobra en el punto de venta.
--
-- Contexto:
--   En este rubro "cancelar" significa pagar, asi que el taller usa la
--   clave `cancelado` para la OT ya cobrada. Esa misma clave es la que el
--   catalogo usa para una anulacion real, y ninguna heuristica puede
--   distinguir los dos sentidos. Por eso el estado de cierre se declara
--   aca en vez de deducirse en el codigo.
--
--   Para saber si una OT termino en trabajo cobrado, los reportes NO miran
--   este estado: miran si tiene comprobante (venta_detalle.ot_id).
--
-- Idempotente. Editable despues desde Configuracion → Estado al cobrar una OT.
--
-- ---------------------------------------------------------------------
-- COMO CORRER
--   mysql -u<user> -p r_scooter < sql/config_estado_cierre_ot.sql
-- ---------------------------------------------------------------------
-- =====================================================================

INSERT INTO `configuracion` (`clave`, `valor`, `tipo`, `grupo`)
VALUES ('estado_cierre_ot', 'cancelado', 'texto', 'general')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

-- =====================================================================
-- VERIFICACION
-- =====================================================================
SELECT 'Estado de cierre configurado' AS control, c.valor AS valor
FROM `configuracion` c WHERE c.clave = 'estado_cierre_ot'
UNION ALL
SELECT 'Existe en estados_ot y es final',
       CASE WHEN EXISTS (
           SELECT 1 FROM `estados_ot` e
           JOIN `configuracion` c2 ON c2.clave = 'estado_cierre_ot'
           WHERE e.clave = c2.valor AND e.es_final = 1 AND e.activo = 1
       ) THEN 'si' ELSE 'NO — revisar' END;
