-- =====================================================================
-- migracion_documento_cliente.sql
--
-- Arregla el documento de identidad de los clientes para facturacion.
--
-- Problema:
--   `actualizacion_servidor_facturacion.sql` agrego a `clientes` las
--   columnas `num_doc`, `tipo_doc` y `razon_social` para SUNAT, con un
--   backfill de una sola vez. Pero ningun formulario del sistema escribe
--   esas columnas: los 4 lugares que crean clientes (clientes/nuevo.php,
--   clientes/editar.php, ot/nueva.php y el alta rapida del POS) escriben
--   `ruc_dni` y nada mas.
--
--   Resultado medido sobre produccion:
--     - 662 clientes con `ruc_dni` cargado
--     -   1 cliente  con `num_doc` cargado
--     - 683 clientes con `tipo_doc = 'dni'`, incluidos los que tienen RUC
--     -   0 clientes con `razon_social`
--
--   SunatBuilder valida `num_doc`, lo encuentra vacio y rechaza TODA
--   factura con "La factura requiere RUC valido (11 digitos)", aunque el
--   cliente tenga su RUC bien cargado en `ruc_dni`.
--
-- Esta migracion sincroniza los datos historicos. El codigo PHP ya no
-- vuelve a desincronizarlos: `sincronizarDocumentoCliente()` corre despues
-- de cada alta o edicion, y SunatService normaliza al vuelo por las dudas.
--
-- Es idempotente y no destructiva: solo completa columnas vacias.
--
-- ---------------------------------------------------------------------
-- COMO CORRER
--   mysqldump -u<user> -p --single-transaction r_scooter > backup_clientes.sql
--   mysql -u<user> -p r_scooter < sql/migracion_documento_cliente.sql
-- ---------------------------------------------------------------------
-- =====================================================================

-- 1. Copiar el documento a `num_doc`, solo digitos, donde este vacio.
UPDATE `clientes`
SET `num_doc` = REGEXP_REPLACE(COALESCE(`ruc_dni`, ''), '[^0-9]', '')
WHERE COALESCE(`num_doc`, '') = ''
  AND COALESCE(`ruc_dni`, '') <> '';

-- 2. Deducir `tipo_doc` de la longitud, que es lo que SUNAT valida.
--    Las longitudes raras (9, 10 digitos) NO se tocan: son datos malos y
--    no corresponde inventarles un tipo. Se listan al final.
UPDATE `clientes` SET `tipo_doc` = 'ruc' WHERE LENGTH(COALESCE(`num_doc`,'')) = 11;
UPDATE `clientes` SET `tipo_doc` = 'dni' WHERE LENGTH(COALESCE(`num_doc`,'')) = 8;

-- 3. Razon social para los clientes con RUC.
--    Solo si esta vacia: si alguien cargo el nombre legal a mano, se respeta.
UPDATE `clientes`
SET `razon_social` = TRIM(`nombre`)
WHERE `tipo_doc` = 'ruc'
  AND COALESCE(`razon_social`, '') = ''
  AND TRIM(COALESCE(`nombre`, '')) <> '';

-- =====================================================================
-- VERIFICACION
-- =====================================================================
SELECT 'Clientes con num_doc cargado' AS control, COUNT(*) AS valor
FROM `clientes` WHERE COALESCE(`num_doc`,'') <> ''
UNION ALL
SELECT 'Clientes con RUC (facturables)', COUNT(*)
FROM `clientes` WHERE `tipo_doc` = 'ruc'
UNION ALL
SELECT 'Clientes con DNI', COUNT(*)
FROM `clientes` WHERE `tipo_doc` = 'dni' AND LENGTH(COALESCE(`num_doc`,'')) = 8
UNION ALL
SELECT 'Documentos de longitud invalida (revisar a mano)', COUNT(*)
FROM `clientes`
WHERE COALESCE(`num_doc`,'') <> ''
  AND LENGTH(`num_doc`) NOT IN (8, 11)
UNION ALL
SELECT 'Clientes sin ningun documento', COUNT(*)
FROM `clientes` WHERE COALESCE(`num_doc`,'') = '';

-- Listado de los documentos con longitud invalida, para corregirlos.
-- Suelen ser telefonos tipeados en el campo equivocado.
SELECT `id`, `codigo`, `nombre`, `num_doc`, LENGTH(`num_doc`) AS digitos
FROM `clientes`
WHERE COALESCE(`num_doc`,'') <> ''
  AND LENGTH(`num_doc`) NOT IN (8, 11)
ORDER BY `id`;
