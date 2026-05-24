-- ─────────────────────────────────────────────────────────────────────────────
-- Tabla empresa: datos del emisor SUNAT y configuración general
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS empresa (
    id          INT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
    ruc         VARCHAR(11)  NOT NULL DEFAULT '',
    razon_social VARCHAR(200) NOT NULL DEFAULT '',
    nombre_comercial VARCHAR(200) DEFAULT '',
    direccion   VARCHAR(300) DEFAULT '',
    ubigeo      VARCHAR(6)   DEFAULT '150101',
    distrito    VARCHAR(100) DEFAULT '',
    provincia   VARCHAR(100) DEFAULT '',
    departamento VARCHAR(100) DEFAULT '',
    telefono    VARCHAR(20)  DEFAULT '',
    email       VARCHAR(150) DEFAULT '',
    logo        VARCHAR(255) DEFAULT '',
    modo        ENUM('beta','produccion') NOT NULL DEFAULT 'beta',
    sunat_usuario_sol VARCHAR(50)  DEFAULT 'MODDATOS',
    sunat_clave_sol   VARCHAR(50)  DEFAULT 'MODDATOS',
    certificadoPem    LONGTEXT     DEFAULT NULL COMMENT 'Contenido .pem codificado en base64',
    fecha_certificado VARCHAR(10)  DEFAULT NULL COMMENT 'Vencimiento del cert',
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (ruc = '' OR CHAR_LENGTH(ruc) = 11)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO empresa (id, razon_social, modo) VALUES (1, 'MI EMPRESA S.A.C.', 'beta');

-- ─────────────────────────────────────────────────────────────────────────────
-- Tabla documentos_empresa: series y correlativos por tipo de comprobante
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS documentos_empresa (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empresa_id   INT UNSIGNED NOT NULL DEFAULT 1,
    tipo         ENUM('factura','boleta','nota_credito','nota_debito') NOT NULL,
    serie        VARCHAR(4)   NOT NULL DEFAULT '',
    numero       INT UNSIGNED NOT NULL DEFAULT 0,
    activo       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY   uq_empresa_tipo (empresa_id, tipo),
    FOREIGN KEY  (empresa_id) REFERENCES empresa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO documentos_empresa (empresa_id, tipo, serie, numero, activo) VALUES
(1, 'factura', 'F001', 0, 1),
(1, 'boleta',  'B001', 0, 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- Agregar campos SUNAT a la tabla ventas existente
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE ventas
    ADD COLUMN sunat_xml        LONGTEXT     DEFAULT NULL COMMENT 'XML generado',
    ADD COLUMN sunat_hash       VARCHAR(100) DEFAULT NULL COMMENT 'Hash SUNAT (BASE64)',
    ADD COLUMN sunat_qr         TEXT         DEFAULT NULL COMMENT 'Info QR codificada',
    ADD COLUMN sunat_cdr        LONGTEXT     DEFAULT NULL COMMENT 'CDR codificado en base64',
    ADD COLUMN sunat_estado     ENUM('pendiente','aceptado','rechazado') DEFAULT NULL,
    ADD COLUMN sunat_mensaje    TEXT         DEFAULT NULL COMMENT 'Respuesta SUNAT',
    ADD COLUMN sunat_codigo     VARCHAR(10)   DEFAULT NULL COMMENT 'Código ticket/ticker',
    ADD COLUMN pdf_token        VARCHAR(80)   DEFAULT NULL COMMENT 'Token público para PDF',
    ADD COLUMN sunat_enviado_at DATETIME     DEFAULT NULL COMMENT 'Fecha de envío a SUNAT',
    ADD COLUMN sunat_aceptado_at DATETIME    DEFAULT NULL COMMENT 'Fecha de aceptación';

-- ─────────────────────────────────────────────────────────────────────────────
-- Agregar campos cliente para SUNAT (DNI/RUC/razón social)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE clientes
    ADD COLUMN tipo_doc     ENUM('dni','ruc','carnet','pasaporte') DEFAULT 'dni',
    ADD COLUMN num_doc      VARCHAR(20)  DEFAULT '' COMMENT 'Número de documento',
    ADD COLUMN razon_social VARCHAR(200) DEFAULT '' COMMENT 'Para facturas (RUC)',
    ADD COLUMN direccion    VARCHAR(300) DEFAULT '';