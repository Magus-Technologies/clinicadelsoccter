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
--
-- Son dos SELECT separados a proposito. `configuracion` es latin1 y
-- `estados_ot` es utf8mb4: unirlos con UNION obliga a MySQL/MariaDB a
-- negociar una collation comun y falla con
-- "Illegal mix of collations ... for operation 'UNION'".
-- Tampoco se usan caracteres fuera de ASCII en los literales, por la
-- misma razon: no existen en latin1.
-- =====================================================================

-- 1) Que quedo guardado.
SELECT `clave`, `valor`
FROM `configuracion`
WHERE `clave` = 'estado_cierre_ot';

-- 2) Que ese estado exista en el catalogo y sea final.
--    CONVERT() alinea las dos collations antes de comparar.
SELECT e.`clave`, e.`label`, e.`es_final`, e.`activo`
FROM `estados_ot` e
WHERE CONVERT(e.`clave` USING utf8mb4) = (
    SELECT CONVERT(c.`valor` USING utf8mb4)
    FROM `configuracion` c
    WHERE c.`clave` = 'estado_cierre_ot'
);
