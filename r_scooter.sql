/*
 Navicat Premium Dump SQL

 Source Server         : ecommerce
 Source Server Type    : MySQL
 Source Server Version : 100527 (10.5.27-MariaDB)
 Source Host           : 173.249.36.119:3306
 Source Schema         : r_scooter

 Target Server Type    : MySQL
 Target Server Version : 100527 (10.5.27-MariaDB)
 File Encoding         : 65001

 Date: 09/05/2026 16:41:52
*/
CREATE DATABASE IF NOT EXISTS `r_scooter` DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci;
USE `r_scooter`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for cajas
-- ----------------------------
DROP TABLE IF EXISTS `cajas`;
CREATE TABLE `cajas`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` int UNSIGNED NOT NULL,
  `fecha` date NOT NULL,
  `saldo_inicial` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `total_ingresos` decimal(10, 2) NULL DEFAULT 0.00,
  `total_egresos` decimal(10, 2) NULL DEFAULT 0.00,
  `saldo_final` decimal(10, 2) NULL DEFAULT 0.00,
  `estado` enum('abierta','cerrada') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'abierta',
  `observaciones` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `fecha_cierre` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `denominaciones_apertura` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `denominaciones_cierre` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `diferencia_cierre` decimal(10, 2) NULL DEFAULT 0.00,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_fecha_usuario`(`fecha` ASC, `usuario_id` ASC) USING BTREE,
  INDEX `usuario_id`(`usuario_id` ASC) USING BTREE,
  CONSTRAINT `cajas_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of cajas
-- ----------------------------

-- ----------------------------
-- Table structure for catalogo_banners
-- ----------------------------
DROP TABLE IF EXISTS `catalogo_banners`;
CREATE TABLE `catalogo_banners`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `titulo` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `subtitulo` varchar(300) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `imagen` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `url_link` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `orden` int NULL DEFAULT 0,
  `activo` tinyint(1) NULL DEFAULT 1,
  `created_at` datetime NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of catalogo_banners
-- ----------------------------
INSERT INTO `catalogo_banners` VALUES (2, '', '', 'img_69f2743960ac69.51710445.jpg', '', 2, 1, '2026-04-29 16:12:25');

-- ----------------------------
-- Table structure for categorias
-- ----------------------------
DROP TABLE IF EXISTS `categorias`;
CREATE TABLE `categorias`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `tipo` enum('repuesto','hardware','ofimatica','accesorio','software') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `descripcion` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 18 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of categorias
-- ----------------------------
INSERT INTO `categorias` VALUES (1, 'Pantallas / Displays', 'repuesto', NULL, 1);
INSERT INTO `categorias` VALUES (2, 'Baterías', 'repuesto', NULL, 1);
INSERT INTO `categorias` VALUES (3, 'Teclados laptop', 'repuesto', NULL, 1);
INSERT INTO `categorias` VALUES (4, 'Placas madre', 'repuesto', NULL, 1);
INSERT INTO `categorias` VALUES (5, 'Fuentes de poder', 'repuesto', NULL, 1);
INSERT INTO `categorias` VALUES (6, 'Discos SSD', 'hardware', NULL, 1);
INSERT INTO `categorias` VALUES (7, 'Discos HDD', 'hardware', NULL, 1);
INSERT INTO `categorias` VALUES (8, 'Memorias RAM', 'hardware', NULL, 1);
INSERT INTO `categorias` VALUES (9, 'Procesadores', 'hardware', NULL, 1);
INSERT INTO `categorias` VALUES (10, 'Tarjetas de video', 'hardware', NULL, 1);
INSERT INTO `categorias` VALUES (11, 'Mouse', 'ofimatica', NULL, 1);
INSERT INTO `categorias` VALUES (12, 'Teclados', 'ofimatica', NULL, 1);
INSERT INTO `categorias` VALUES (13, 'Cables y adaptadores', 'accesorio', NULL, 1);
INSERT INTO `categorias` VALUES (14, 'Audífonos / Headsets', 'accesorio', NULL, 1);
INSERT INTO `categorias` VALUES (15, 'Antivirus / Licencias', 'software', NULL, 1);
INSERT INTO `categorias` VALUES (16, 'Pads térmicos / Pasta', 'repuesto', NULL, 1);
INSERT INTO `categorias` VALUES (17, 'Ventiladores / Coolers', 'repuesto', NULL, 1);

-- ----------------------------
-- Table structure for checklist_items
-- ----------------------------
DROP TABLE IF EXISTS `checklist_items`;
CREATE TABLE `checklist_items`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `activo` tinyint(1) NULL DEFAULT 1,
  `orden` int NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 29 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of checklist_items
-- ----------------------------
INSERT INTO `checklist_items` VALUES (22, '¿Prende?', 1, 1);
INSERT INTO `checklist_items` VALUES (24, '¿Carga?', 1, 3);
INSERT INTO `checklist_items` VALUES (25, '¿Frenos en buen estado?', 1, 4);
INSERT INTO `checklist_items` VALUES (26, '¿Llantas en buen estado?', 1, 5);
INSERT INTO `checklist_items` VALUES (27, '¿Deja pertenencias?', 1, 6);
INSERT INTO `checklist_items` VALUES (28, '¿Abonó al ingreso?', 1, 7);

-- ----------------------------
-- Table structure for clientes
-- ----------------------------
DROP TABLE IF EXISTS `clientes`;
CREATE TABLE `clientes`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL COMMENT 'CLI-0001',
  `tipo` enum('persona','empresa') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'persona',
  `nombre` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `ruc_dni` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `email` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `telefono` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `whatsapp` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `direccion` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `distrito` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `segmento` enum('nuevo','frecuente','empresa','vip') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'nuevo',
  `notas` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `codigo`(`codigo` ASC) USING BTREE,
  INDEX `idx_ruc_dni`(`ruc_dni` ASC) USING BTREE,
  INDEX `idx_telefono`(`telefono` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 597 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of clientes
-- ----------------------------
INSERT INTO `clientes` VALUES (596, 'CLI-0001', 'persona', 'JOSE CARLOS RAMOS PANTA', '70074661', 'jc.ramospanta@gmail.com', '51937808447', '51937808447', NULL, NULL, 'nuevo', NULL, 1, '2026-05-08 00:29:56', '2026-05-08 00:29:56');

-- ----------------------------
-- Table structure for compra_detalle
-- ----------------------------
DROP TABLE IF EXISTS `compra_detalle`;
CREATE TABLE `compra_detalle`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `compra_id` int UNSIGNED NOT NULL,
  `producto_id` int UNSIGNED NOT NULL,
  `cantidad` decimal(10, 2) NOT NULL,
  `precio_unit` decimal(10, 2) NOT NULL,
  `subtotal` decimal(10, 2) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `compra_id`(`compra_id` ASC) USING BTREE,
  INDEX `producto_id`(`producto_id` ASC) USING BTREE,
  CONSTRAINT `compra_detalle_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `compra_detalle_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of compra_detalle
-- ----------------------------

-- ----------------------------
-- Table structure for compras
-- ----------------------------
DROP TABLE IF EXISTS `compras`;
CREATE TABLE `compras`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `proveedor` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `tipo_doc` enum('factura','boleta','guia','sin_doc') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'factura',
  `nro_doc` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `total` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `metodo_pago` enum('efectivo','transferencia','tarjeta','credito') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'efectivo',
  `notas` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `usuario_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `usuario_id`(`usuario_id` ASC) USING BTREE,
  CONSTRAINT `compras_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of compras
-- ----------------------------

-- ----------------------------
-- Table structure for configuracion
-- ----------------------------
DROP TABLE IF EXISTS `configuracion`;
CREATE TABLE `configuracion`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `clave` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `valor` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `tipo` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'texto',
  `grupo` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'general',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `clave`(`clave` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 33 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of configuracion
-- ----------------------------
INSERT INTO `configuracion` VALUES (1, 'empresa_nombre', 'La Clinica del Scooter', 'texto', 'empresa');
INSERT INTO `configuracion` VALUES (2, 'empresa_ruc', '10738701840', 'texto', 'empresa');
INSERT INTO `configuracion` VALUES (3, 'empresa_direccion', 'Lima, Perú', 'texto', 'empresa');
INSERT INTO `configuracion` VALUES (4, 'empresa_telefono', '900599364', 'texto', 'empresa');
INSERT INTO `configuracion` VALUES (5, 'empresa_email', 'renato.rm.97@gmail.com', 'texto', 'empresa');
INSERT INTO `configuracion` VALUES (6, 'empresa_logo', '', 'imagen', 'empresa');
INSERT INTO `configuracion` VALUES (7, 'igv_porcentaje', '18', 'numero', 'facturacion');
INSERT INTO `configuracion` VALUES (8, 'garantia_defecto_dias', '15', 'numero', 'reparaciones');
INSERT INTO `configuracion` VALUES (9, 'whatsapp_api_token', '', 'texto', 'notificaciones');
INSERT INTO `configuracion` VALUES (10, 'whatsapp_phone_id', '', 'texto', 'notificaciones');
INSERT INTO `configuracion` VALUES (11, 'smtp_host', '', 'texto', 'email');
INSERT INTO `configuracion` VALUES (12, 'smtp_user', 'admin@techrepair.com', 'texto', 'email');
INSERT INTO `configuracion` VALUES (13, 'smtp_pass', '', 'texto', 'email');
INSERT INTO `configuracion` VALUES (14, 'smtp_port', '587', 'numero', 'email');
INSERT INTO `configuracion` VALUES (15, 'moneda', 'PEN', 'texto', 'general');
INSERT INTO `configuracion` VALUES (16, 'moneda_simbolo', 'S/', 'texto', 'general');
INSERT INTO `configuracion` VALUES (17, 'catalogo_nombre', 'Catálogo', 'texto', 'catalogo');
INSERT INTO `configuracion` VALUES (18, 'catalogo_whatsapp', '51972781904', 'texto', 'catalogo');
INSERT INTO `configuracion` VALUES (19, 'catalogo_mensaje_wa', 'Hola, me interesa el producto: {producto} (S/ {precio}). ¿Está disponible?', 'texto', 'catalogo');
INSERT INTO `configuracion` VALUES (20, 'catalogo_color_primario', '#0d9488', 'texto', 'catalogo');
INSERT INTO `configuracion` VALUES (21, 'catalogo_mostrar_precio', '1', 'numero', 'catalogo');
INSERT INTO `configuracion` VALUES (22, 'catalogo_productos_por_pagina', '12', 'numero', 'catalogo');
INSERT INTO `configuracion` VALUES (23, 'print_cabecera', 'Servicio técnico especializado en scooters eléctricos\r\nMantenimiento • Reparación • Diagnóstico electrónico\r\nAtención Lun-Vier | 11  - 8 pm | Sab | 11 - 6 pm', 'texto', 'impresion');
INSERT INTO `configuracion` VALUES (24, 'print_cuentas', 'Cuenta BCP Soles es 19398454026009.\r\nCuenta interbancaria es 00219319845402600912.\r\nA nombre de Renato Machuca.', 'texto', 'impresion');
INSERT INTO `configuracion` VALUES (25, 'print_msg_inferior', '', 'texto', 'impresion');
INSERT INTO `configuracion` VALUES (26, 'print_despedida', 'Agradecemos su confianza, doc. ¡Maneje con cuidado!', 'texto', 'impresion');
INSERT INTO `configuracion` VALUES (27, 'print_mostrar_logo', '1', 'texto', 'impresion');
INSERT INTO `configuracion` VALUES (28, 'print_mostrar_qr', '1', 'texto', 'impresion');
INSERT INTO `configuracion` VALUES (29, 'print_mostrar_cabecera', '1', 'texto', 'impresion');
INSERT INTO `configuracion` VALUES (30, 'print_mostrar_inferior', '1', 'texto', 'impresion');
INSERT INTO `configuracion` VALUES (31, 'print_mostrar_despedida', '1', 'texto', 'impresion');
INSERT INTO `configuracion` VALUES (32, 'print_logo', 'logos/logo_empresa.png', 'texto', 'impresion');

-- ----------------------------
-- Table structure for equipos
-- ----------------------------
DROP TABLE IF EXISTS `equipos`;
CREATE TABLE `equipos`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo_equipo_id` int UNSIGNED NOT NULL,
  `cliente_id` int UNSIGNED NOT NULL,
  `marca` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `modelo` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `serial` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `color` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `descripcion` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `tipo_equipo_id`(`tipo_equipo_id` ASC) USING BTREE,
  INDEX `cliente_id`(`cliente_id` ASC) USING BTREE,
  INDEX `idx_serial`(`serial` ASC) USING BTREE,
  CONSTRAINT `equipos_ibfk_1` FOREIGN KEY (`tipo_equipo_id`) REFERENCES `tipos_equipo` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `equipos_ibfk_2` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1064 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of equipos
-- ----------------------------
INSERT INTO `equipos` VALUES (1062, 12, 596, 'Ninebot', 'ES2', '70074661', 'Negro', 'sticker de \"go and fix\" en el mastil.', '2026-05-08 00:29:56');

-- ----------------------------
-- Table structure for estados_ot
-- ----------------------------
DROP TABLE IF EXISTS `estados_ot`;
CREATE TABLE `estados_ot`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `clave` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'clave_interna sin espacios',
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Texto visible',
  `color` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'secondary' COMMENT 'Bootstrap color',
  `icono` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'circle',
  `orden` int NOT NULL DEFAULT 0,
  `es_final` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=no permite más cambios',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `clave`(`clave` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 12 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of estados_ot
-- ----------------------------
INSERT INTO `estados_ot` VALUES (1, 'ingresado', 'Ingresado', 'warning', 'search', 1, 0, 1, '2026-05-05 20:24:43');
INSERT INTO `estados_ot` VALUES (2, 'en_revision', 'En mesa', 'warning', 'tool', 2, 0, 1, '2026-05-05 20:24:43');
INSERT INTO `estados_ot` VALUES (3, 'en_reparacion', 'En desarme', 'danger', 'tool', 3, 0, 1, '2026-05-05 20:24:43');
INSERT INTO `estados_ot` VALUES (6, 'cancelado', 'cancelado', 'secondary', 'x-circle', 9, 1, 1, '2026-05-05 20:24:43');
INSERT INTO `estados_ot` VALUES (7, 'en_proforma', 'En cotización', 'danger', 'tool', 4, 0, 1, '2026-05-07 23:53:15');
INSERT INTO `estados_ot` VALUES (8, 'para_testeo', 'Listo para testeo', 'success', 'check-circle', 5, 0, 1, '2026-05-08 00:02:54');
INSERT INTO `estados_ot` VALUES (9, 'para_detail', 'Listo para detailing', 'success', 'check-circle', 6, 0, 1, '2026-05-08 00:03:07');
INSERT INTO `estados_ot` VALUES (10, 'para_recojo', 'Cliente citado', 'success', 'check-circle', 7, 0, 1, '2026-05-08 00:04:42');
INSERT INTO `estados_ot` VALUES (11, 'archivado', 'Finalizado', 'secondary', 'inbox', 8, 1, 1, '2026-05-08 00:06:33');

-- ----------------------------
-- Table structure for fotos_ot
-- ----------------------------
DROP TABLE IF EXISTS `fotos_ot`;
CREATE TABLE `fotos_ot`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ot_id` int UNSIGNED NULL DEFAULT NULL,
  `ruta` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `tipo_archivo` enum('foto','video') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'foto',
  `duracion_seg` int NULL DEFAULT NULL,
  `tamano_bytes` int UNSIGNED NULL DEFAULT NULL,
  `descripcion` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `tipo` enum('ingreso','proceso','entrega') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'ingreso',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `ot_id`(`ot_id` ASC) USING BTREE,
  CONSTRAINT `fotos_ot_ibfk_1` FOREIGN KEY (`ot_id`) REFERENCES `ordenes_trabajo` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 26 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of fotos_ot
-- ----------------------------

-- ----------------------------
-- Table structure for garantias
-- ----------------------------
DROP TABLE IF EXISTS `garantias`;
CREATE TABLE `garantias`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo` enum('reparacion','producto') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `referencia_id` int UNSIGNED NOT NULL COMMENT 'ot_id o venta_id',
  `cliente_id` int UNSIGNED NOT NULL,
  `descripcion` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_vence` date NOT NULL,
  `estado` enum('vigente','vencida','reclamada') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'vigente',
  `notas` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `cliente_id`(`cliente_id` ASC) USING BTREE,
  CONSTRAINT `garantias_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of garantias
-- ----------------------------

-- ----------------------------
-- Table structure for historial_ot
-- ----------------------------
DROP TABLE IF EXISTS `historial_ot`;
CREATE TABLE `historial_ot`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ot_id` int UNSIGNED NOT NULL,
  `usuario_id` int UNSIGNED NOT NULL,
  `estado_antes` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `estado_nuevo` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `comentario` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `ot_id`(`ot_id` ASC) USING BTREE,
  INDEX `usuario_id`(`usuario_id` ASC) USING BTREE,
  CONSTRAINT `historial_ot_ibfk_1` FOREIGN KEY (`ot_id`) REFERENCES `ordenes_trabajo` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `historial_ot_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 48 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of historial_ot
-- ----------------------------
INSERT INTO `historial_ot` VALUES (45, 1059, 1, NULL, 'ingresado', 'OT creada', '2026-05-08 00:29:56');
INSERT INTO `historial_ot` VALUES (46, 1059, 1, 'ingresado', 'en_reparacion', '', '2026-05-08 00:32:26');

-- ----------------------------
-- Table structure for kardex
-- ----------------------------
DROP TABLE IF EXISTS `kardex`;
CREATE TABLE `kardex`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `producto_id` int UNSIGNED NOT NULL,
  `tipo` enum('entrada','salida','ajuste','devolucion') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `cantidad` decimal(10, 2) NOT NULL,
  `stock_antes` decimal(10, 2) NOT NULL,
  `stock_despues` decimal(10, 2) NOT NULL,
  `precio_unit` decimal(10, 2) NULL DEFAULT 0.00,
  `motivo` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `referencia` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL COMMENT 'OT-2024-0001 o VTA-0001',
  `usuario_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `usuario_id`(`usuario_id` ASC) USING BTREE,
  INDEX `idx_producto_fecha`(`producto_id` ASC, `created_at` ASC) USING BTREE,
  CONSTRAINT `kardex_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `kardex_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of kardex
-- ----------------------------
INSERT INTO `kardex` VALUES (5, 3, 'entrada', 100.00, 0.00, 100.00, 10.00, 'Stock inicial', 'INICIO', 1, '2026-04-28 00:27:54');
INSERT INTO `kardex` VALUES (6, 3, 'salida', 1.00, 100.00, 99.00, 40.00, 'Venta', 'VTA-2026-0001', 1, '2026-04-28 00:30:21');

-- ----------------------------
-- Table structure for marcas_equipo
-- ----------------------------
DROP TABLE IF EXISTS `marcas_equipo`;
CREATE TABLE `marcas_equipo`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `activo` tinyint(1) NULL DEFAULT 1,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `nombre`(`nombre` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 51 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of marcas_equipo
-- ----------------------------
INSERT INTO `marcas_equipo` VALUES (20, 'Xiaomi', 1);
INSERT INTO `marcas_equipo` VALUES (41, 'Ninebot', 1);
INSERT INTO `marcas_equipo` VALUES (42, 'Kaabo', 1);
INSERT INTO `marcas_equipo` VALUES (43, 'Vsett', 1);
INSERT INTO `marcas_equipo` VALUES (44, 'Dualtron', 1);
INSERT INTO `marcas_equipo` VALUES (45, 'Roadtrip', 1);
INSERT INTO `marcas_equipo` VALUES (46, 'Kukirin', 1);
INSERT INTO `marcas_equipo` VALUES (47, 'Yadea', 1);
INSERT INTO `marcas_equipo` VALUES (49, 'Otros', 1);
INSERT INTO `marcas_equipo` VALUES (50, 'Kingsong', 1);

-- ----------------------------
-- Table structure for movimientos_caja
-- ----------------------------
DROP TABLE IF EXISTS `movimientos_caja`;
CREATE TABLE `movimientos_caja`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `caja_id` int UNSIGNED NOT NULL,
  `tipo` enum('ingreso','egreso') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `concepto` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `monto` decimal(10, 2) NOT NULL,
  `referencia` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `usuario_id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `caja_id`(`caja_id` ASC) USING BTREE,
  INDEX `usuario_id`(`usuario_id` ASC) USING BTREE,
  CONSTRAINT `movimientos_caja_ibfk_1` FOREIGN KEY (`caja_id`) REFERENCES `cajas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `movimientos_caja_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of movimientos_caja
-- ----------------------------

-- ----------------------------
-- Table structure for notificaciones
-- ----------------------------
DROP TABLE IF EXISTS `notificaciones`;
CREATE TABLE `notificaciones`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ot_id` int UNSIGNED NULL DEFAULT NULL,
  `cliente_id` int UNSIGNED NULL DEFAULT NULL,
  `tipo` enum('whatsapp','email','sistema') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `asunto` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `mensaje` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `estado` enum('pendiente','enviado','error') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'pendiente',
  `enviado_at` datetime NULL DEFAULT NULL,
  `error_msg` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `ot_id`(`ot_id` ASC) USING BTREE,
  INDEX `cliente_id`(`cliente_id` ASC) USING BTREE,
  CONSTRAINT `notificaciones_ibfk_1` FOREIGN KEY (`ot_id`) REFERENCES `ordenes_trabajo` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `notificaciones_ibfk_2` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of notificaciones
-- ----------------------------

-- ----------------------------
-- Table structure for ordenes_trabajo
-- ----------------------------
DROP TABLE IF EXISTS `ordenes_trabajo`;
CREATE TABLE `ordenes_trabajo`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo_ot` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL COMMENT 'OT-2024-0001',
  `codigo_publico` varchar(12) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL COMMENT 'Para consulta del cliente: ABC12345',
  `cliente_id` int UNSIGNED NOT NULL,
  `equipo_id` int UNSIGNED NOT NULL,
  `servicio_id` int UNSIGNED NULL DEFAULT NULL,
  `tecnico_id` int UNSIGNED NULL DEFAULT NULL,
  `usuario_creador_id` int UNSIGNED NOT NULL,
  `estado` enum('ingresado','en_revision','en_reparacion','listo','entregado','cancelado') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'ingresado',
  `problema_reportado` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL COMMENT 'Lo que dice el cliente',
  `diagnostico_inicial` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL COMMENT 'Primera revisión del técnico',
  `diagnostico_tecnico` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL COMMENT 'Diagnóstico detallado',
  `observaciones` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Estado físico del equipo al ingreso',
  `costo_diagnostico` decimal(10, 2) NULL DEFAULT 0.00,
  `costo_repuestos` decimal(10, 2) NULL DEFAULT 0.00,
  `costo_mano_obra` decimal(10, 2) NULL DEFAULT 0.00,
  `costo_total` decimal(10, 2) NULL DEFAULT 0.00,
  `descuento` decimal(10, 2) NULL DEFAULT 0.00,
  `precio_final` decimal(10, 2) NULL DEFAULT 0.00,
  `presupuesto_aprobado` tinyint(1) NULL DEFAULT 0,
  `aprobado_por` enum('firma','whatsapp','llamada','email') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'firma',
  `fecha_aprobacion` datetime NULL DEFAULT NULL,
  `firma_cliente` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL COMMENT 'SVG base64 de la firma',
  `garantia_dias` int NULL DEFAULT 30,
  `garantia_vence` date NULL DEFAULT NULL,
  `fecha_ingreso` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_estimada` date NULL DEFAULT NULL,
  `fecha_entrega` datetime NULL DEFAULT NULL,
  `pagado` tinyint(1) NULL DEFAULT 0,
  `metodo_pago` enum('efectivo','yape','plin','tarjeta','transferencia') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `fecha_pago` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `codigo_ot`(`codigo_ot` ASC) USING BTREE,
  UNIQUE INDEX `codigo_publico`(`codigo_publico` ASC) USING BTREE,
  INDEX `cliente_id`(`cliente_id` ASC) USING BTREE,
  INDEX `equipo_id`(`equipo_id` ASC) USING BTREE,
  INDEX `tecnico_id`(`tecnico_id` ASC) USING BTREE,
  INDEX `usuario_creador_id`(`usuario_creador_id` ASC) USING BTREE,
  INDEX `idx_estado`(`estado` ASC) USING BTREE,
  INDEX `idx_codigo_publico`(`codigo_publico` ASC) USING BTREE,
  INDEX `idx_fecha_ingreso`(`fecha_ingreso` ASC) USING BTREE,
  INDEX `servicio_id`(`servicio_id` ASC) USING BTREE,
  CONSTRAINT `ordenes_trabajo_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ordenes_trabajo_ibfk_2` FOREIGN KEY (`equipo_id`) REFERENCES `equipos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ordenes_trabajo_ibfk_3` FOREIGN KEY (`tecnico_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ordenes_trabajo_ibfk_4` FOREIGN KEY (`usuario_creador_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1061 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of ordenes_trabajo
-- ----------------------------
INSERT INTO `ordenes_trabajo` VALUES (1059, 'OT-2026-0001', '42930938', 596, 1062, 1, NULL, 1, 'en_reparacion', 'mantenimiento', 'ningún problema', NULL, NULL, '{\"¿Prende?\":\"bueno\",\"¿Carga?\":\"bueno\",\"¿Frenos en buen estado?\":\"bueno\",\"¿Llantas en buen estado?\":\"bueno\",\"¿Deja pertenencias?\":\"bueno\",\"¿Abonó al ingreso?\":\"malo\",\"_observacion\":\"deja cargador.\"}', 0.00, 9.00, 190.00, 199.00, 0.00, 199.00, 0, 'firma', NULL, 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB2aWV3Qm94PSIwIDAgMzY0IDEyMCIgd2lkdGg9IjM2NCIgaGVpZ2h0PSIxMjAiPjxwYXRoIGQ9Ik0gOTEuNTY3LDc3LjAwMCBDIDg5Ljc4Myw3NC4zMzcgOTAuNDUwLDc0LjIwOCA4OS4zMzMsNzEuNDE3IiBzdHJva2Utd2lkdGg9IjYuMjc4IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gODkuMzMzLDcxLjQxNyBDIDg5LjMzMyw2Ni45NTAgODguNjY2LDY3LjA3OSA4OS4zMzMsNjIuNDgzIiBzdHJva2Utd2lkdGg9IjQuNzc2IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gODkuMzMzLDYyLjQ4MyBDIDg5LjMzMyw1Ny40NTggODkuMzMzLDU3LjQ1OCA4OS4zMzMsNTIuNDMzIiBzdHJva2Utd2lkdGg9IjQuMzE3IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gODkuMzMzLDUyLjQzMyBDIDg5LjMzMyw0Ni4yOTIgODkuMzMzLDQ2LjI5MiA4OS4zMzMsNDAuMTUwIiBzdHJva2Utd2lkdGg9IjMuOTU1IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gODkuMzMzLDQwLjE1MCBDIDg5LjMzMywzNC41NjcgODkuMzMzLDM0LjU2NyA4OS4zMzMsMjguOTgzIiBzdHJva2Utd2lkdGg9IjQuMDM4IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gODkuMzMzLDI4Ljk4MyBDIDkxLjE0OCwyMC43MjAgODkuMzMzLDIzLjQwMCA4OS4zMzMsMTcuODE3IiBzdHJva2Utd2lkdGg9IjQuMDYzIiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gODkuMzMzLDE3LjgxNyBDIDg2LjMwMSwxOC41MjAgODguMzU2LDE2LjI1MyA4My43NTAsMjAuMDUwIiBzdHJva2Utd2lkdGg9IjUuNTQ5IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gODMuNzUwLDIwLjA1MCBDIDgxLjM1MSwyMy4wMzEgODAuNzE3LDIxLjg3MCA3OC4xNjcsMjQuNTE3IiBzdHJva2Utd2lkdGg9IjQuOTY2IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gNzguMTY3LDI0LjUxNyBDIDcyLjYxMywyNi4yOTUgNzIuOTc2LDI2LjkzOSA2Ny4wMDAsMjcuODY3IiBzdHJva2Utd2lkdGg9IjQuMjUxIiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gNjcuMDAwLDI3Ljg2NyBDIDU4LjcxNiwzMC43OTQgNTguNjU0LDMwLjIwMyA1MC4yNTAsMzIuMzMzIiBzdHJva2Utd2lkdGg9IjMuNDg5IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gNTAuMjUwLDMyLjMzMyBDIDQyLjE4MiwzMy4wODggNDYuNDMzLDMzLjAyNyA0Mi40MzMsMzIuMzMzIiBzdHJva2Utd2lkdGg9IjQuMjEzIiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gNDIuNDMzLDMyLjMzMyBDIDQ2Ljg3OCwzMC41OTkgNDIuNzQwLDMxLjQxMyA1MS4zNjcsMjguOTgzIiBzdHJva2Utd2lkdGg9IjUuNTU3IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gNTEuMzY3LDI4Ljk4MyBDIDYwLjczMiwyNS4yMjIgNjAuODM3LDI1LjU3NCA3MC4zNTAsMjIuMjgzIiBzdHJva2Utd2lkdGg9IjMuNDcwIiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gNzAuMzUwLDIyLjI4MyBDIDgwLjk0MywxOS40MzIgODAuODMyLDE5LjA4MSA5MS41NjcsMTYuNzAwIiBzdHJva2Utd2lkdGg9IjMuMzc1IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gOTEuNTY3LDE2LjcwMCBDIDEwMC41MDAsMTQuNDY3IDEwMC40ODUsMTQuNDA3IDEwOS40MzMsMTIuMjMzIiBzdHJva2Utd2lkdGg9IjMuMzc1IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTA5LjQzMywxMi4yMzMgQyAxMjAuNzE0LDkuODA4IDEyMC42MDAsOS40NDIgMTMxLjc2Nyw2LjY1MCIgc3Ryb2tlLXdpZHRoPSIzLjM3NSIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDEzMS43NjcsNi42NTAgQyAxMzYuNDczLDUuNDEzIDEzNi4zNDcsNS4zNDEgMTQwLjcwMCwzLjMwMCIgc3Ryb2tlLXdpZHRoPSIzLjcwMCIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDEwNi4wODMsNDAuMTUwIEMgMTEwLjAwNyw0MS4yNjcgMTA5Ljk5Miw0MC43MDggMTEzLjkwMCw0MS4yNjciIHN0cm9rZS13aWR0aD0iNi4yNjkiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxMTMuOTAwLDQxLjI2NyBDIDExNi43OTYsNDEuMDQ2IDExNi43MDcsNDEuMjY3IDExOS40ODMsNDAuMTUwIiBzdHJva2Utd2lkdGg9IjUuMzY4IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTE5LjQ4Myw0MC4xNTAgQyAxMjMuMjc5LDM4LjE0MCAxMjMuNDk2LDM4LjgxMiAxMjcuMzAwLDM2LjgwMCIgc3Ryb2tlLXdpZHRoPSI0LjcyOSIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDEyNy4zMDAsMzYuODAwIEMgMTMyLjAxMiwzNi4zMDUgMTMxLjY1NCwzNS4zNDkgMTM2LjIzMywzNC41NjciIHN0cm9rZS13aWR0aD0iNC4zMzMiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxMzYuMjMzLDM0LjU2NyBDIDE0NS4yNDYsMzEuNDA0IDE0MS41MDQsMzIuMzk2IDE0Ni4yODMsMjguOTgzIiBzdHJva2Utd2lkdGg9IjQuMTE0IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTQ2LjI4MywyOC45ODMgQyAxNDMuMDI5LDI3LjI4MCAxNDYuOTIxLDI3LjQ5NiAxMzkuNTgzLDI2Ljc1MCIgc3Ryb2tlLXdpZHRoPSI1LjUxOCIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDEzOS41ODMsMjYuNzUwIEMgMTM1LjY3NSwyNi43NTAgMTM1Ljc3MCwyNi4xNjMgMTMxLjc2NywyNi43NTAiIHN0cm9rZS13aWR0aD0iNC43OTEiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxMzEuNzY3LDI2Ljc1MCBDIDEyOC40MTcsMjYuNzUwIDEyOC40MTcsMjYuNzUwIDEyNS4wNjcsMjYuNzUwIiBzdHJva2Utd2lkdGg9IjUuMzM3IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTI1LjA2NywyNi43NTAgQyAxMjEuODQxLDI1LjcwMiAxMjIuMjc1LDI2Ljc1MCAxMTkuNDgzLDI2Ljc1MCIgc3Ryb2tlLXdpZHRoPSI1LjYzNSIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDExOS40ODMsMjYuNzUwIEMgMTE2LjQ4NSwyOC41NzQgMTE2LjgxNiwyNy45MzUgMTE1LjAxNywzMS4yMTciIHN0cm9rZS13aWR0aD0iNi4wOTgiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxMTUuMDE3LDMxLjIxNyBDIDExMy45MDAsMzQuMDA4IDExMy42OTMsMzMuNTk5IDExMy45MDAsMzYuODAwIiBzdHJva2Utd2lkdGg9IjUuODU1IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTEzLjkwMCwzNi44MDAgQyAxMTMuNjk0LDM5Ljk1NyAxMTMuOTAwLDM5LjU5MiAxMTUuMDE3LDQyLjM4MyIgc3Ryb2tlLXdpZHRoPSI1LjIxMiIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDExNS4wMTcsNDIuMzgzIEMgMTE3LjUzNiw0Ni4wMjAgMTE3LjA0Myw0Ni4wOTkgMTIwLjYwMCw0OS4wODMiIHN0cm9rZS13aWR0aD0iNS4yNzYiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxMjAuNjAwLDQ5LjA4MyBDIDEyMi42NDcsNTEuMDg5IDEyMi41NjEsNTEuMDQ1IDEyNS4wNjcsNTIuNDMzIiBzdHJva2Utd2lkdGg9IjUuNjE1IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTI1LjA2Nyw1Mi40MzMgQyAxMjcuNzQ1LDU0LjEzOCAxMjcuNjcyLDUzLjg4MSAxMzAuNjUwLDU0LjY2NyIgc3Ryb2tlLXdpZHRoPSI2LjA2MCIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDEzMC42NTAsNTQuNjY3IEMgMTM0LjMwNyw1NS42ODEgMTMzLjg4Nyw1NS4yNTUgMTM3LjM1MCw1NC42NjciIHN0cm9rZS13aWR0aD0iNS45NzQiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxMzcuMzUwLDU0LjY2NyBDIDE0MC42NTQsNTIuMzYxIDE0MS4wMDcsNTMuNDQ4IDE0NC4wNTAsNTAuMjAwIiBzdHJva2Utd2lkdGg9IjUuNTgwIiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTUyLjk4MywxNy44MTcgQyAxNTMuODE4LDIwLjU4MSAxNTMuNTQyLDIwLjYwOCAxNTQuMTAwLDIzLjQwMCIgc3Ryb2tlLXdpZHRoPSI2LjQ1OCIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDE1NC4xMDAsMjMuNDAwIEMgMTUzLjg0OCwyNi4yMTIgMTU0LjM3NiwyNi4xNjQgMTU0LjEwMCwyOC45ODMiIHN0cm9rZS13aWR0aD0iNS4zNjYiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxNTQuMTAwLDI4Ljk4MyBDIDE1NC4xNTUsMzIuNDk3IDE1NC40MDYsMzIuMzU0IDE1NS4yMTcsMzUuNjgzIiBzdHJva2Utd2lkdGg9IjQuOTExIiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTU1LjIxNywzNS42ODMgQyAxNTcuMDE4LDM4Ljk3NyAxNTYuMzg4LDM5LjE5NyAxNTguNTY3LDQyLjM4MyIgc3Ryb2tlLXdpZHRoPSI0LjY4NyIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDE1OC41NjcsNDIuMzgzIEMgMTYwLjIyMSw0NS4wNzEgMTU5LjgxMCw0NS4xMTggMTYwLjgwMCw0Ny45NjciIHN0cm9rZS13aWR0aD0iNS4zNTkiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxNjAuODAwLDQ3Ljk2NyBDIDE2MC4yNjIsNTAuODYyIDE2MS4zMzgsNTAuNjU1IDE2MC44MDAsNTMuNTUwIiBzdHJva2Utd2lkdGg9IjUuMTYxIiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTgyLjAxNywxNy44MTcgQyAxODIuNjUzLDIxLjE1NSAxODIuNTc1LDIxLjE2NyAxODMuMTMzLDI0LjUxNyIgc3Ryb2tlLXdpZHRoPSI2LjI4MyIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDE4My4xMzMsMjQuNTE3IEMgMTgzLjI0NiwyOS4wOTggMTgzLjc3MCwyOC45NzIgMTg0LjI1MCwzMy40NTAiIHN0cm9rZS13aWR0aD0iNS41ODkiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxODQuMjUwLDMzLjQ1MCBDIDE4NS41MzQsMzYuMTg1IDE4NC45MjEsMzYuMzU3IDE4Ni40ODMsMzkuMDMzIiBzdHJva2Utd2lkdGg9IjUuMTUzIiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTg2LjQ4MywzOS4wMzMgQyAxODYuODMyLDQzLjM5NCAxODcuNzY3LDQyLjg4NSAxODguNzE3LDQ2Ljg1MCIgc3Ryb2tlLXdpZHRoPSI1LjMzNCIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDE4OC43MTcsNDYuODUwIEMgMTkwLjUxNiw1MC4xMzEgMTkwLjE4MSw0OS41MzYgMTkzLjE4Myw1MS4zMTciIHN0cm9rZS13aWR0aD0iNS43ODciIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxOTMuMTgzLDUxLjMxNyBDIDE5Ni4zMTksNTIuMjk2IDE5NS41NDEsNTIuMzY0IDE5OC43NjcsNTEuMzE3IiBzdHJva2Utd2lkdGg9IjYuMTEzIiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTk4Ljc2Nyw1MS4zMTcgQyAyMDIuNzU4LDQ5Ljc2OSAyMDEuOTAyLDUwLjA2MyAyMDQuMzUwLDQ2Ljg1MCIgc3Ryb2tlLXdpZHRoPSI1Ljk1NiIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDIwNC4zNTAsNDYuODUwIEMgMjA0Ljg2Niw0Mi45MzUgMjA2LjEwOCw0My42MjcgMjA1LjQ2NywzOS4wMzMiIHN0cm9rZS13aWR0aD0iNS41NjYiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAyMDUuNDY3LDM5LjAzMyBDIDIwNi41ODMsMzUuNjk5IDIwNS45ODMsMzUuNjc3IDIwNi41ODMsMzIuMzMzIiBzdHJva2Utd2lkdGg9IjUuNTg4IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMjA2LjU4MywzMi4zMzMgQyAyMDYuNzUwLDI3LjA5NCAyMDYuNTgzLDI3LjMyNCAyMDUuNDY3LDIyLjI4MyIgc3Ryb2tlLXdpZHRoPSI1LjI1OSIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDE2Ny41MDAsMjMuNDAwIEMgMTY0LjA5OCwyNS4xNDAgMTY0LjcwOCwyNS42MzMgMTYxLjkxNywyNy44NjciIHN0cm9rZS13aWR0aD0iNi40ODQiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxNjEuOTE3LDI3Ljg2NyBDIDE2MC4wMDQsMzEuMTE4IDE1OS42MzEsMzAuNzIzIDE1OC41NjcsMzQuNTY3IiBzdHJva2Utd2lkdGg9IjUuMDM3IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTU4LjU2NywzNC41NjcgQyAxNTcuNzg2LDM4LjA3MSAxNTcuMjEyLDM3LjgxOCAxNTYuMzMzLDQxLjI2NyIgc3Ryb2tlLXdpZHRoPSI0Ljc4NCIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDE1Ni4zMzMsNDEuMjY3IEMgMTU0LjY1OCw0NC4wNTggMTU0Ljk5NCw0NC4yMTMgMTUyLjk4Myw0Ni44NTAiIHN0cm9rZS13aWR0aD0iNC44NTEiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxNTIuOTgzLDQ2Ljg1MCBDIDE0OC40NzcsNTAuODE0IDE1MS4zMDgsNDkuNjQyIDE0OS42MzMsNTIuNDMzIiBzdHJva2Utd2lkdGg9IjUuMzU4IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTQ5LjYzMyw1Mi40MzMgQyAxNTQuMTMyLDUzLjQzNSAxNTEuMjY5LDU0LjcyMyAxNTguNTY3LDU0LjY2NyIgc3Ryb2tlLXdpZHRoPSI1Ljg1NSIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDE1OC41NjcsNTQuNjY3IEMgMTY0LjQ3NCw1NS41NzUgMTY0LjE4Miw1Ni4yMjYgMTY5LjczMyw1OC4wMTciIHN0cm9rZS13aWR0aD0iNC4zMzgiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxNjkuNzMzLDU4LjAxNyBDIDE4MC4wNDMsNjMuMTgzIDE4MC4xMDcsNjIuODMzIDE4OS44MzMsNjkuMTgzIiBzdHJva2Utd2lkdGg9IjMuMzc1IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTg5LjgzMyw2OS4xODMgQyAxOTcuMzkxLDczLjgyMSAxOTcuMzUxLDczLjc5MSAyMDQuMzUwLDc5LjIzMyIgc3Ryb2tlLXdpZHRoPSIzLjM3NSIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDIwNC4zNTAsNzkuMjMzIEMgMjExLjk4OCw4Ni4xNjEgMjEyLjQ2Niw4NS41NDYgMjE5Ljk4Myw5Mi42MzMiIHN0cm9rZS13aWR0aD0iMy4zNzUiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAyMTkuOTgzLDkyLjYzMyBDIDIyOS4xMzIsOTcuMzI5IDIyMy43MTMsOTUuNjUzIDIyNy44MDAsOTguMjE3IiBzdHJva2Utd2lkdGg9IjMuODEwIiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMjI3LjgwMCw5OC4yMTcgQyAyMjAuNTAxLDk4LjAwMiAyMjUuNzgyLDk5LjU2MiAyMTMuMjgzLDk3LjEwMCIgc3Ryb2tlLXdpZHRoPSI1LjA3MCIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDIxMy4yODMsOTcuMTAwIEMgMjAyLjczMiw5NC45NzQgMjAyLjYzNSw5NS43NjkgMTkyLjA2Nyw5My43NTAiIHN0cm9rZS13aWR0aD0iMy40MDIiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSAxOTIuMDY3LDkzLjc1MCBDIDE2OC42NDcsOTEuMTEzIDE2OC42NzQsOTEuMDY2IDE0NS4xNjcsODkuMjgzIiBzdHJva2Utd2lkdGg9IjMuMzc1IiBzdHJva2U9IiMxYTFkMjMiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+PC9wYXRoPjxwYXRoIGQ9Ik0gMTQ1LjE2Nyw4OS4yODMgQyAxMjUuNjQwLDg3LjY1MiAxMjUuNjU2LDg3Ljc2MyAxMDYuMDgzLDg3LjA1MCIgc3Ryb2tlLXdpZHRoPSIzLjM3NSIgc3Ryb2tlPSIjMWExZDIzIiBmaWxsPSJub25lIiBzdHJva2UtbGluZWNhcD0icm91bmQiPjwvcGF0aD48cGF0aCBkPSJNIDEwNi4wODMsODcuMDUwIEMgODkuMzI0LDg2LjcwOCA4OS4zNDgsODYuNTM1IDcyLjU4Myw4Ny4wNTAiIHN0cm9rZS13aWR0aD0iMy4zNzUiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PHBhdGggZD0iTSA3Mi41ODMsODcuMDUwIEMgNjEuNzcwLDg2LjYyNSA2MS45NjYsODcuMjY3IDUxLjM2Nyw4OC4xNjciIHN0cm9rZS13aWR0aD0iMy4zNzUiIHN0cm9rZT0iIzFhMWQyMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48L3BhdGg+PC9zdmc+', 15, NULL, '2026-05-08 00:29:56', '2026-05-11', NULL, 0, NULL, NULL, '2026-05-08 00:29:56', '2026-05-08 00:32:26');

-- ----------------------------
-- Table structure for ot_repuestos
-- ----------------------------
DROP TABLE IF EXISTS `ot_repuestos`;
CREATE TABLE `ot_repuestos`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ot_id` int UNSIGNED NOT NULL,
  `producto_id` int UNSIGNED NULL DEFAULT NULL,
  `descripcion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cantidad` decimal(10, 2) NOT NULL DEFAULT 1.00,
  `precio_unit` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(10, 2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `ot_id`(`ot_id` ASC) USING BTREE,
  CONSTRAINT `ot_repuestos_ibfk_1` FOREIGN KEY (`ot_id`) REFERENCES `ordenes_trabajo` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1465 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of ot_repuestos
-- ----------------------------

-- ----------------------------
-- Table structure for productos
-- ----------------------------
DROP TABLE IF EXISTS `productos`;
CREATE TABLE `productos`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `nombre` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `descripcion` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `categoria_id` int UNSIGNED NOT NULL,
  `marca` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `modelo` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `serial` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `ubicacion` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL COMMENT 'Estante/fila/columna en almacén',
  `precio_costo` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `precio_venta` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `stock_actual` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `stock_minimo` decimal(10, 2) NOT NULL DEFAULT 1.00,
  `stock_maximo` decimal(10, 2) NULL DEFAULT 100.00,
  `unidad` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'unidad',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE CURRENT_TIMESTAMP,
  `visible_catalogo` tinyint(1) NULL DEFAULT 0 COMMENT 'Mostrar en catálogo público',
  `precio_oferta` decimal(10, 2) NULL DEFAULT NULL,
  `descripcion_larga` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `fotos_catalogo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Array de rutas de imágenes',
  `destacado` tinyint(1) NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `codigo`(`codigo` ASC) USING BTREE,
  INDEX `idx_categoria`(`categoria_id` ASC) USING BTREE,
  INDEX `idx_stock_minimo`(`stock_actual` ASC, `stock_minimo` ASC) USING BTREE,
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of productos
-- ----------------------------
INSERT INTO `productos` VALUES (3, 'PRD-00003', 'Cámara 8.5', '', 2, 'CST', 'Pitón recto', '', 'Estante central 2 fila', 10.00, 40.00, 99.00, 20.00, 100.00, 'unidad', 1, '2026-04-28 00:27:54', '2026-04-30 14:58:39', 1, NULL, NULL, NULL, 0);

-- ----------------------------
-- Table structure for servicio_repuestos
-- ----------------------------
DROP TABLE IF EXISTS `servicio_repuestos`;
CREATE TABLE `servicio_repuestos`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `servicio_id` int UNSIGNED NOT NULL,
  `producto_id` int UNSIGNED NOT NULL,
  `cantidad` decimal(10, 2) NOT NULL DEFAULT 1.00,
  `precio_referencial` decimal(10, 2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `servicio_id`(`servicio_id` ASC) USING BTREE,
  INDEX `producto_id`(`producto_id` ASC) USING BTREE,
  CONSTRAINT `sr_ibfk_1` FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `sr_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of servicio_repuestos
-- ----------------------------

-- ----------------------------
-- Table structure for servicios
-- ----------------------------
DROP TABLE IF EXISTS `servicios`;
CREATE TABLE `servicios`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `descripcion` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `categoria` enum('diagnostico','reparacion','mantenimiento','instalacion','otro') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'reparacion',
  `precio` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `precio_minimo` decimal(10, 2) NULL DEFAULT NULL,
  `duracion_estimada` int NULL DEFAULT NULL COMMENT 'En minutos',
  `garantia_dias` int NOT NULL DEFAULT 30,
  `requiere_repuestos` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `notas` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of servicios
-- ----------------------------
INSERT INTO `servicios` VALUES (1, 'Mantenimiento sin Suspensión', '', 'mantenimiento', 190.00, 190.00, NULL, 15, 1, 1, '', '2026-04-29 13:08:36', '2026-04-30 12:32:55');
INSERT INTO `servicios` VALUES (2, 'Mantenimiento con 1 Suspensión', '', 'mantenimiento', 230.00, 230.00, NULL, 15, 1, 1, '', '2026-04-30 12:33:16', '2026-04-30 12:33:16');
INSERT INTO `servicios` VALUES (3, 'Mantenimiento DUAL Suspensión', '', 'mantenimiento', 260.00, 260.00, NULL, 15, 1, 1, '', '2026-04-30 12:33:35', '2026-04-30 12:33:35');
INSERT INTO `servicios` VALUES (4, 'Revisión y diagnóstico Electrónico', '', 'diagnostico', 90.00, 90.00, NULL, 0, 1, 1, '', '2026-04-30 12:34:13', '2026-05-08 15:02:50');
INSERT INTO `servicios` VALUES (5, 'Revisión y diagnóstico Mecánico', '', 'diagnostico', 90.00, 90.00, NULL, 0, 1, 1, '', '2026-04-30 12:34:29', '2026-05-08 15:02:58');
INSERT INTO `servicios` VALUES (6, 'Revisión y diagnóstico Mecánico', '', 'diagnostico', 90.00, 90.00, NULL, 0, 1, 1, '', '2026-04-30 12:34:29', '2026-05-08 15:03:05');

-- ----------------------------
-- Table structure for tipos_equipo
-- ----------------------------
DROP TABLE IF EXISTS `tipos_equipo`;
CREATE TABLE `tipos_equipo`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `icono` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'laptop',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 18 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of tipos_equipo
-- ----------------------------
INSERT INTO `tipos_equipo` VALUES (12, 'Scooter Electrico', 'package', 1);
INSERT INTO `tipos_equipo` VALUES (15, 'Moto Eléctrica', 'package', 1);
INSERT INTO `tipos_equipo` VALUES (16, 'Monociclo', 'package', 1);
INSERT INTO `tipos_equipo` VALUES (17, 'Otros', 'package', 1);

-- ----------------------------
-- Table structure for usuarios
-- ----------------------------
DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `apellido` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `email` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `rol` enum('admin','tecnico','vendedor') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'tecnico',
  `telefono` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `avatar` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_acceso` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `email`(`email` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 10 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of usuarios
-- ----------------------------
INSERT INTO `usuarios` VALUES (1, 'Administrador', 'Sistema', 'admin@techrepair.com', '$2y$10$FKiL0COIMN5qXEtmtgNI5uoRpiJP22.aE7GhaXCMma3mHNI52vslm', 'admin', NULL, NULL, 1, '2026-05-09 16:36:29', '2026-04-13 08:46:29', '2026-05-09 16:36:29');
INSERT INTO `usuarios` VALUES (3, 'Jose Carlos', 'Ramos', 'josecarlos@techrepair.com', '$2y$10$Lf1bMaCW6NxizmL9dhuEIuW2sbbnubnSo1wxL8MDtMLnUIMv0OXlG', 'admin', '937808447', NULL, 1, NULL, '2026-05-07 22:47:26', '2026-05-07 23:37:04');
INSERT INTO `usuarios` VALUES (8, 'José Carlos', 'Ramos Panta', 'jc.ramospanta@gmail.com', '$2y$10$aIsqjOtbVnLSnVqAvvkFUunAq8KAtfPfIIEGOsAt3oRpRpLd9WJOm', 'admin', '51937808447', NULL, 1, '2026-05-08 17:55:09', '2026-05-08 00:17:30', '2026-05-08 17:55:09');
INSERT INTO `usuarios` VALUES (9, 'Cesar', 'Chu', 'cesarchu12102002@gmail.com', '$2y$10$YZNDo3eHPi..w4rF6i0Unu0KQDrqalJb3Wi.sMdGjx9RkrJc5B8ra', 'admin', '923492746', NULL, 1, '2026-05-08 17:53:59', '2026-05-08 17:52:44', '2026-05-08 17:53:59');

-- ----------------------------
-- Table structure for venta_detalle
-- ----------------------------
DROP TABLE IF EXISTS `venta_detalle`;
CREATE TABLE `venta_detalle`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `venta_id` int UNSIGNED NOT NULL,
  `producto_id` int UNSIGNED NOT NULL,
  `cantidad` decimal(10, 2) NOT NULL,
  `precio_unit` decimal(10, 2) NOT NULL,
  `descuento` decimal(10, 2) NULL DEFAULT 0.00,
  `subtotal` decimal(10, 2) NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `venta_id`(`venta_id` ASC) USING BTREE,
  INDEX `producto_id`(`producto_id` ASC) USING BTREE,
  CONSTRAINT `venta_detalle_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `venta_detalle_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of venta_detalle
-- ----------------------------

-- ----------------------------
-- Table structure for ventas
-- ----------------------------
DROP TABLE IF EXISTS `ventas`;
CREATE TABLE `ventas`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL COMMENT 'VTA-2024-0001',
  `cliente_id` int UNSIGNED NULL DEFAULT NULL,
  `usuario_id` int UNSIGNED NOT NULL,
  `tipo_doc` enum('boleta','factura','ticket','sin_comprobante') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'boleta',
  `serie_doc` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `num_doc` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `subtotal` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `igv` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `descuento` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `total` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `metodo_pago` enum('efectivo','yape','plin','tarjeta','transferencia','mixto') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'efectivo',
  `monto_pagado` decimal(10, 2) NULL DEFAULT NULL,
  `vuelto` decimal(10, 2) NULL DEFAULT 0.00,
  `estado` enum('completada','anulada','pendiente') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'completada',
  `notas` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `codigo`(`codigo` ASC) USING BTREE,
  INDEX `cliente_id`(`cliente_id` ASC) USING BTREE,
  INDEX `usuario_id`(`usuario_id` ASC) USING BTREE,
  INDEX `idx_fecha`(`created_at` ASC) USING BTREE,
  INDEX `idx_estado`(`estado` ASC) USING BTREE,
  CONSTRAINT `ventas_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ventas_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of ventas
-- ----------------------------

-- ----------------------------
-- Table structure for wa_plantillas
-- ----------------------------
DROP TABLE IF EXISTS `wa_plantillas`;
CREATE TABLE `wa_plantillas`  (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `categoria` enum('reparacion','venta','general') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT 'general',
  `texto` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `usuario_id` int UNSIGNED NULL DEFAULT NULL,
  `activo` tinyint(1) NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 12 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of wa_plantillas
-- ----------------------------
INSERT INTO `wa_plantillas` VALUES (1, 'Equipo listo para recoger', 'reparacion', 'Hola {nombre} í ½í±\n\nÂ¡Tu equipo ya estÃ¡ *listo para recoger* en {empresa}! í ¼í¾\n\nCÃ³digo OT: *{codigo_ot}*\nCÃ³digo consulta: *{codigo_consulta}*\n\nRecuerda traer tu DNI. Â¡Te esperamos!', NULL, 1, '2026-04-28 18:50:05');
INSERT INTO `wa_plantillas` VALUES (2, 'Equipo en reparaciÃ³n', 'reparacion', 'Hola {nombre}, tu equipo estÃ¡ siendo reparado en {empresa} í ½í´§\n\nOT: *{codigo_ot}*\n\nTe avisamos en cuanto estÃ© listo.', NULL, 1, '2026-04-28 18:50:05');
INSERT INTO `wa_plantillas` VALUES (3, 'Presupuesto para aprobaciÃ³n', 'reparacion', 'Hola {nombre} í ½í±\n\nHemos revisado tu equipo en {empresa} y el presupuesto estÃ¡ listo.\n\nOT: *{codigo_ot}*\nTotal: *{total}*\n\nResponde para confirmar. Â¡Gracias!', NULL, 1, '2026-04-28 18:50:05');
INSERT INTO `wa_plantillas` VALUES (4, 'Equipo entregado - Gracias', 'reparacion', 'Â¡Gracias por confiar en {empresa}! í ½í¹\n\nTu equipo fue entregado correctamente.\nOT: *{codigo_ot}*\n\nRecuerda que tienes garantÃ­a. Â¡Hasta pronto!', NULL, 1, '2026-04-28 18:50:05');
INSERT INTO `wa_plantillas` VALUES (5, 'Recordatorio de recojo', 'reparacion', 'Hola {nombre}, te recordamos que tu equipo lleva varios dÃ­as listo en {empresa}.\n\nOT: *{codigo_ot}*\n\nPor favor coordina el recojo. Â¡Gracias!', NULL, 1, '2026-04-28 18:50:05');
INSERT INTO `wa_plantillas` VALUES (6, 'Consulta estado en lÃ­nea', 'reparacion', 'Hola {nombre} í ½í´\n\nPuedes consultar el estado de tu reparaciÃ³n en lÃ­nea con tu cÃ³digo: *{codigo_consulta}*', NULL, 1, '2026-04-28 18:50:05');
INSERT INTO `wa_plantillas` VALUES (7, 'DiagnÃ³stico listo', 'reparacion', 'Hola {nombre}, ya tenemos el diagnÃ³stico de tu equipo en {empresa} í ½í´¬\n\nOT: *{codigo_ot}*\nFecha estimada: *{fecha_estimada}*\n\nÂ¿Deseas que procedamos con la reparaciÃ³n?', NULL, 1, '2026-04-28 18:50:05');
INSERT INTO `wa_plantillas` VALUES (8, 'PromociÃ³n / Oferta especial', 'venta', 'Hola {nombre} í ½í±\n\n{empresa} tiene ofertas especiales para ti esta semana. VisÃ­tanos o escrÃ­benos para mÃ¡s informaciÃ³n. Â¡Te esperamos!', NULL, 1, '2026-04-28 18:50:05');
INSERT INTO `wa_plantillas` VALUES (9, 'CotizaciÃ³n de producto', 'venta', 'Hola {nombre}, gracias por tu consulta en {empresa} í ½í¸\n\nTe enviamos la cotizaciÃ³n solicitada. Â¿Tienes alguna pregunta?', NULL, 1, '2026-04-28 18:50:05');
INSERT INTO `wa_plantillas` VALUES (10, 'Saludo general', 'general', 'Hola {nombre}, gracias por contactar a {empresa} í ½í¸\n\nÂ¿En quÃ© podemos ayudarte hoy?', NULL, 1, '2026-04-28 18:50:05');
INSERT INTO `wa_plantillas` VALUES (11, 'Aviso de cierre / horario', 'general', 'Hola {nombre} í ½í±\n\nTe informamos que {empresa} atiende de lunes a sÃ¡bado.\n\nEscrÃ­benos cuando gustes. Â¡Gracias!', NULL, 1, '2026-04-28 18:50:05');

SET FOREIGN_KEY_CHECKS = 1;
