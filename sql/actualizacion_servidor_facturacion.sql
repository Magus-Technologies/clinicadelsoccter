-- ═══════════════════════════════════════════════════════════════════
-- SQL DE ACTUALIZACIÓN r_scooter (servidor)
-- Ejecutar en producción para activar facturación SUNAT
-- ═══════════════════════════════════════════════════════════════════

-- ── 1. ALTER ventas: agregar columnas SUNAT ────────────────────
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS serie VARCHAR(4) DEFAULT '' AFTER tipo_doc;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS numero INT UNSIGNED DEFAULT 0 AFTER serie;

-- Renombrar columnas viejas si existen (y tienen datos)
-- Esto migra datos de serie_doc/num_doc a serie/numero
UPDATE ventas SET serie = serie_doc, numero = num_doc WHERE serie_doc IS NOT NULL AND serie_doc != '';

ALTER TABLE ventas ADD COLUMN IF NOT EXISTS sunat_xml LONGTEXT DEFAULT NULL AFTER total;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS sunat_hash VARCHAR(100) DEFAULT NULL;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS sunat_qr TEXT DEFAULT NULL;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS sunat_cdr LONGTEXT DEFAULT NULL;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS sunat_estado ENUM('pendiente','aceptado','rechazado') DEFAULT NULL;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS sunat_mensaje TEXT DEFAULT NULL;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS sunat_codigo VARCHAR(10) DEFAULT NULL;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS pdf_token VARCHAR(80) DEFAULT NULL;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS sunat_enviado_at DATETIME DEFAULT NULL;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS sunat_aceptado_at DATETIME DEFAULT NULL;

-- ── 2. ALTER clientes: campos para SUNAT ────────────────────────
ALTER TABLE clientes ADD COLUMN IF NOT EXISTS tipo_doc ENUM('dni','ruc','carnet','pasaporte') DEFAULT 'dni' AFTER telefono;
ALTER TABLE clientes ADD COLUMN IF NOT EXISTS num_doc VARCHAR(20) DEFAULT '' COMMENT 'Número de documento' AFTER tipo_doc;
ALTER TABLE clientes ADD COLUMN IF NOT EXISTS razon_social VARCHAR(200) DEFAULT '' COMMENT 'Para facturas (RUC)' AFTER num_doc;

-- Migrar datos: clientes con ruc_dni de 11 dígitos → tipo_doc='ruc'
UPDATE clientes SET tipo_doc = 'ruc', num_doc = ruc_dni WHERE ruc_dni IS NOT NULL AND LENGTH(ruc_dni) = 11;
UPDATE clientes SET tipo_doc = 'dni', num_doc = ruc_dni WHERE ruc_dni IS NOT NULL AND LENGTH(ruc_dni) = 8;
-- Migrar razon_social desde nombre para clientes tipo empresa
UPDATE clientes SET razon_social = nombre WHERE tipo = 'empresa';

-- ── 3. ALTER venta_detalle: agregar columna concepto ────────────
ALTER TABLE venta_detalle ADD COLUMN IF NOT EXISTS concepto VARCHAR(255) DEFAULT NULL AFTER producto_id;

-- ── 4. Crear tabla documentos_empresa ──────────────────────────
CREATE TABLE IF NOT EXISTS documentos_empresa (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT UNSIGNED NOT NULL DEFAULT 1,
    tipo        ENUM('factura','boleta') NOT NULL,
    serie       VARCHAR(4) NOT NULL DEFAULT '',
    numero      INT UNSIGNED NOT NULL DEFAULT 0,
    activo      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY  uq_empresa_tipo (empresa_id, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar series por defecto
INSERT IGNORE INTO documentos_empresa (empresa_id, tipo, serie, numero, activo) VALUES
(1, 'factura', 'F001', 0, 1),
(1, 'boleta',  'B001', 0, 1);

-- ── 5. Configuración SUNAT ──────────────────────────────────────
INSERT IGNORE INTO configuracion (clave, valor, tipo, grupo) VALUES
('sunat_usuario_sol', 'MODDATOS', 'texto', 'sunat'),
('sunat_clave_sol',   'MODDATOS', 'texto', 'sunat'),
('sunat_modo',        'beta',     'texto', 'sunat'),
('sunat_certificado', '',         'texto', 'sunat');

-- Actualizar RUC y nombre empresa (con los datos actuales del servidor)
UPDATE configuracion SET valor='10738701840' WHERE clave='empresa_ruc';
UPDATE configuracion SET valor='La Clinica del Scooter' WHERE clave='empresa_nombre';

-- ── 6. Actualizar igv_porcentaje a 18 (SUNAT requiere 18%) ─────
UPDATE configuracion SET valor='18' WHERE clave='igv_porcentaje';

-- ── 7. usuarios: asegurar que tengan rol correcto ───────────────
-- El rol 'admin' ya existe en el enum del servidor, pero el código local usa ROL_ADMIN
-- Si el enum tiene 'admin' ya está bien. Solo aseguramos que exista admin.
UPDATE usuarios SET rol='admin' WHERE email='admin@techrepair.com';

-- ── 8. Crear índices para optimize queries ──────────────────────
ALTER TABLE ventas ADD INDEX IF NOT EXISTS idx_sunat_estado (sunat_estado);
ALTER TABLE ventas ADD INDEX IF NOT EXISTS idx_created_at (created_at);
ALTER TABLE ventas ADD INDEX IF NOT EXISTS idx_tipo_doc (tipo_doc);
ALTER TABLE clientes ADD INDEX IF NOT EXISTS idx_num_doc (num_doc);

-- ── 9. No hacer cambios en productos (el servidor tiene sus propios datos) ──

-- ── 10. Listo para producción ──