ALTER TABLE clientes
    ADD COLUMN tipo_doc ENUM('dni','ruc','carnet','pasaporte') DEFAULT 'dni' AFTER activo,
    ADD COLUMN num_doc VARCHAR(20) DEFAULT '' COMMENT 'Número de documento' AFTER tipo_doc,
    ADD COLUMN razon_social VARCHAR(200) DEFAULT '' COMMENT 'Para facturas (RUC)' AFTER num_doc;