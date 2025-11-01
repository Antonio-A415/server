-- phpMyAdmin SQL Dump
-- version 5.1.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 30-10-2025 a las 19:28:54
-- Versión del servidor: 10.4.22-MariaDB
-- Versión de PHP: 8.1.1

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `app`
--

DELIMITER $$
--
-- Procedimientos
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `CrearNuevoContratoConPago` (IN `p_id_usuario` INT, IN `p_id_administrador` INT, IN `p_id_paquete` INT, IN `p_id_promocion` INT, IN `p_duracion` INT, IN `p_clausulas` TEXT, IN `p_monto_pago` DECIMAL(10,2), IN `p_metodo_pago` ENUM('tarjeta','efectivo','transferencia'), IN `p_detalle_pago` VARCHAR(200))  BEGIN
    DECLARE v_id_contrato INT;
    DECLARE v_id_pago INT;
    
    
    
    
    START TRANSACTION;

    
    INSERT INTO contratos (
        id_usuario, id_administrador, id_paquete, id_promocion,
        fecha_cobro, estado, duracion, clausulas
    )
    VALUES (
        p_id_usuario, p_id_administrador, p_id_paquete, p_id_promocion,
        
        DATE_ADD(CURDATE(), INTERVAL 1 MONTH), 
        'activo', 
        p_duracion, p_clausulas
    );

    
    SET v_id_contrato = LAST_INSERT_ID();

    
    INSERT INTO pagos (id_contrato, metodo_pago, monto_pago, estado)
    VALUES (v_id_contrato, p_metodo_pago, p_monto_pago, 'completado');

    
    SET v_id_pago = LAST_INSERT_ID();

    
    INSERT INTO pagos_detalles (id_pago, detalle)
    VALUES (v_id_pago, p_detalle_pago);
    
    
    UPDATE usuarios
    SET estado_cuenta = 'activo'
    WHERE id = p_id_usuario AND estado_cuenta = 'inactivo';

    
    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `DesbloquearUsuarios` ()  BEGIN
    UPDATE usuarios
    SET intentos_login = 0, bloqueado_hasta = NULL
    WHERE bloqueado_hasta IS NOT NULL AND bloqueado_hasta < NOW();
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `LimpiarCodigosExpirados` ()  BEGIN
    DELETE FROM codigos_verificacion
    WHERE fecha_expiracion < NOW() OR intentos >= 3;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `LimpiarSesionesExpiradas` ()  BEGIN
    -- 1. Desactivar sesiones cuya expiración ha pasado
    UPDATE sesiones SET activa = FALSE
    WHERE fecha_expiracion < NOW() AND activa = TRUE;

    -- 2. Eliminar sesiones que expiraron hace más de 30 días
    DELETE FROM sesiones
    WHERE fecha_expiracion < DATE_SUB(NOW(), INTERVAL 30 DAY);
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `administradores`
--

CREATE TABLE `administradores` (
  `id` int(11) NOT NULL,
  `nombres` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellidos` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usuario` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grado_academico` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Usuarios con permisos de administración.';

--
-- Volcado de datos para la tabla `administradores`
--

INSERT INTO `administradores` (`id`, `nombres`, `apellidos`, `usuario`, `password`, `grado_academico`, `telefono`, `direccion`, `correo`, `fecha_creacion`) VALUES
(1, 'Maria Regina', 'Cauich Ceca', 'AdminGlobal', 'pass_admin_hash', 'Licenciatura en TI', '5555000001', 'Oficina Central, Piso 10', 'admin@keyli.com', '2025-10-30 05:03:37'),
(2, 'Arix Rogelio', 'Chan Cab', 'SupervisoraA', 'pass_sup_hash', 'Maestría en Administración', '5555000002', 'Sucursal Norte, Área Manager', 'super_a@keyli.com', '2025-10-30 05:03:37'),
(3, 'Leysi ester', 'Cauich Ceca', 'GerenteFact', 'pass_ger_hash', 'Técnico Contable', '5555000003', 'Área de Cobranza', 'cobranza@keyli.com', '2025-10-30 05:03:37');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `asistencia`
--

CREATE TABLE `asistencia` (
  `id` int(11) NOT NULL,
  `id_tecnico` int(11) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_atencion` timestamp NOT NULL DEFAULT current_timestamp(),
  `detalles` text COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de solicitudes de asistencia técnica.';

--
-- Volcado de datos para la tabla `asistencia`
--

INSERT INTO `asistencia` (`id`, `id_tecnico`, `id_usuario`, `fecha_atencion`, `detalles`) VALUES
(1, 1, 1, '2025-10-28 05:03:37', 'Reporte de baja velocidad en hora pico. Se reconfiguró el router.'),
(2, 2, 3, '2025-10-25 05:03:37', 'Solicitud de instalación de nuevo servicio. Instalación completada.'),
(3, 3, 4, '2025-10-20 05:03:37', 'Falla total del servicio. Se detectó corte de fibra en el poste cercano.');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `codigos_verificacion`
--

CREATE TABLE `codigos_verificacion` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `codigo` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_verificacion` enum('correo','whatsapp') COLLATE utf8mb4_unicode_ci NOT NULL,
  `usado` tinyint(4) DEFAULT 0,
  `intentos` int(11) DEFAULT 0,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_expiracion` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Códigos temporales para verificación.';

--
-- Volcado de datos para la tabla `codigos_verificacion`
--

INSERT INTO `codigos_verificacion` (`id`, `usuario_id`, `codigo`, `tipo_verificacion`, `usado`, `intentos`, `fecha_creacion`, `fecha_expiracion`) VALUES
(1, 2, '123456', 'correo', 0, 0, '2025-10-30 05:03:37', '2025-10-30 05:13:37'),
(2, 3, '000000', 'whatsapp', 1, 1, '2025-10-29 05:03:37', '2025-10-29 05:13:37'),
(3, 4, '999999', 'correo', 0, 5, '2025-10-30 04:43:37', '2025-10-30 04:53:37');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contratos`
--

CREATE TABLE `contratos` (
  `id` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `id_administrador` int(11) NOT NULL,
  `id_paquete` int(11) NOT NULL,
  `id_promocion` int(11) DEFAULT NULL,
  `fecha_contrato` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_cobro` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `estado` enum('activo','inactivo') COLLATE utf8mb4_unicode_ci NOT NULL,
  `duracion` int(11) DEFAULT NULL COMMENT 'Duración del contrato en meses',
  `clausulas` text COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contratos de servicio firmados.';

--
-- Volcado de datos para la tabla `contratos`
--

INSERT INTO `contratos` (`id`, `id_usuario`, `id_administrador`, `id_paquete`, `id_promocion`, `fecha_contrato`, `fecha_cobro`, `estado`, `duracion`, `clausulas`) VALUES
(1, 1, 1, 2, 2, '2025-10-15 05:03:37', '2025-11-15 05:03:37', 'activo', 12, 'Cláusula de permanencia de 12 meses.'),
(2, 3, 2, 1, 1, '2025-10-25 05:03:37', '2025-11-25 05:03:37', 'activo', 6, 'Cláusula por promoción de 6 meses.'),
(3, 4, 3, 3, NULL, '2025-04-30 05:03:37', '2025-05-30 05:03:37', 'inactivo', 24, 'Contrato largo, terminado por falta de pago.'),
(4, 4, 2, 1, NULL, '2025-10-30 07:52:36', '2025-11-30 06:00:00', 'activo', 6, 'Contrato por promoción inicial.');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs_actividad`
--

CREATE TABLE `logs_actividad` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `accion` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `detalles` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de acciones de los usuarios.';

--
-- Volcado de datos para la tabla `logs_actividad`
--

INSERT INTO `logs_actividad` (`id`, `usuario_id`, `accion`, `detalles`, `ip_address`, `user_agent`, `fecha`) VALUES
(1, 2, 'registro', 'Intento de registro sin verificar', '192.168.1.10', 'Chrome 120', '2025-10-30 02:03:37'),
(2, 1, 'login_exitoso', 'Login desde móvil', '172.20.5.8', 'iOS Safari', '2025-10-30 04:03:37'),
(3, 4, 'login_fallido', 'Contraseña incorrecta', '10.0.0.5', 'Firefox 110', '2025-10-30 04:33:37'),
(4, 3, 'cambio_plan', 'Cambió de plan Premium a Básico', '203.0.113.44', 'Chrome 121', '2025-10-30 05:03:37');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pagos`
--

CREATE TABLE `pagos` (
  `id` int(11) NOT NULL,
  `id_contrato` int(11) NOT NULL,
  `metodo_pago` enum('tarjeta','efectivo','transferencia') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `monto_pago` decimal(10,2) DEFAULT NULL COMMENT 'Ajustado a decimal(10, 2) para pagos',
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado` enum('completado','pendiente') COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de pagos de contratos.';

--
-- Volcado de datos para la tabla `pagos`
--

INSERT INTO `pagos` (`id`, `id_contrato`, `metodo_pago`, `monto_pago`, `fecha`, `estado`) VALUES
(1, 1, 'tarjeta', '899.99', '2025-10-20 05:03:37', 'completado'),
(2, 2, 'efectivo', '175.00', '2025-10-27 05:03:37', 'completado'),
(3, 3, 'transferencia', '1500.50', '2025-05-30 05:03:37', 'completado'),
(4, 1, 'tarjeta', '899.99', '2025-09-20 05:03:37', 'completado'),
(5, 4, 'efectivo', '450.00', '2025-10-30 07:52:36', 'completado');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pagos_detalles`
--

CREATE TABLE `pagos_detalles` (
  `id` int(11) NOT NULL,
  `id_pago` int(11) NOT NULL,
  `detalle` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Detalles de los componentes incluidos en un pago.';

--
-- Volcado de datos para la tabla `pagos_detalles`
--

INSERT INTO `pagos_detalles` (`id`, `id_pago`, `detalle`) VALUES
(1, 1, 'pago servicios de instalacion'),
(2, 2, 'pago mensual'),
(3, 3, 'pago mensual'),
(4, 4, 'pago de servicios'),
(5, 5, 'Pago de instalación y primer mes de servicio');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `paquetes_internet`
--

CREATE TABLE `paquetes_internet` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `velocidad_subida` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detalles` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `precio` decimal(10,2) DEFAULT NULL COMMENT 'Ajustado a decimal(10, 2) para precios',
  `estado` enum('activo','inactivo') COLLATE utf8mb4_unicode_ci NOT NULL,
  `velocidad_bajada` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Planes de servicio de internet ofrecidos.';

--
-- Volcado de datos para la tabla `paquetes_internet`
--

INSERT INTO `paquetes_internet` (`id`, `nombre`, `velocidad_subida`, `tipo`, `detalles`, `precio`, `estado`, `velocidad_bajada`) VALUES
(1, 'Básico Hogar', '10 Mbps', 'Fibra Óptica', 'Ideal para navegación y streaming SD', '350.00', 'activo', '50 Mbps'),
(2, 'Premium Gamer', '50 Mbps', 'Fibra Óptica', 'Para juegos en línea y múltiples dispositivos', '899.99', 'activo', '300 Mbps'),
(3, 'Empresarial PyME', '100 Mbps', 'Fibra Simétrica', 'Conexión garantizada para negocios', '1500.50', 'activo', '100 Mbps'),
(4, 'Plan Antiguo', '5 Mbps', 'Cable Coaxial', 'Plan solo para clientes viejos', '299.00', 'inactivo', '20 Mbps');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `promociones_temporales`
--

CREATE TABLE `promociones_temporales` (
  `id` int(11) NOT NULL,
  `id_paquete` int(11) DEFAULT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_inicio` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `condiciones` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` enum('activo','inactivo') COLLATE utf8mb4_unicode_ci NOT NULL,
  `descuento` decimal(5,2) DEFAULT NULL COMMENT 'Ajustado a decimal(5, 2) para descuentos'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Promociones asociadas a paquetes.';

--
-- Volcado de datos para la tabla `promociones_temporales`
--

INSERT INTO `promociones_temporales` (`id`, `id_paquete`, `nombre`, `fecha_inicio`, `fecha_fin`, `condiciones`, `estado`, `descuento`) VALUES
(1, 1, '3 Meses al 50%', '2025-10-30 05:03:37', '2026-01-28 05:03:37', 'Solo para nuevos clientes.', 'activo', '50.00'),
(2, 2, 'Descuento 1er Mes', '2025-10-30 05:03:37', '2025-11-29 05:03:37', 'Válido al contratar el plan Premium.', 'activo', '25.00'),
(3, NULL, 'Mes Gratis (Sin Paquete)', '2025-08-31 05:03:37', '2025-09-30 05:03:37', 'Promoción expirada.', 'inactivo', '100.00');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sesiones`
--

CREATE TABLE `sesiones` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `token_sesion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_inicio` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_expiracion` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `activa` tinyint(4) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de sesiones activas.';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tecnicos`
--

CREATE TABLE `tecnicos` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grado_academico` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Personal técnico para asistencia e instalaciones.';

--
-- Volcado de datos para la tabla `tecnicos`
--

INSERT INTO `tecnicos` (`id`, `nombre`, `grado_academico`, `telefono`, `direccion`, `correo`, `fecha_creacion`) VALUES
(1, 'Carlos Hernández', 'Ingeniero de Redes', '5555111111', 'Base Sur, Sector A', 'carlos.h@tech.com', '2025-10-30 05:03:37'),
(2, 'Laura Mendiola', 'Técnico en Fibra', '5555222222', 'Base Centro, Almacén 3', 'laura.m@tech.com', '2025-10-30 05:03:37'),
(3, 'Ricardo Soto', 'Soporte Básico', '5555333333', 'Base Norte, Taller', 'ricardo.s@tech.com', '2025-10-30 05:03:37');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre_user` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `correo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recordar_usuario` tinyint(1) DEFAULT 0,
  `correo_verificado` tinyint(1) DEFAULT 0,
  `telefono_verificado` tinyint(1) DEFAULT 0,
  `estado_cuenta` enum('activo','inactivo','suspendido') COLLATE utf8mb4_unicode_ci DEFAULT 'inactivo',
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ultimo_acceso` timestamp NULL DEFAULT NULL,
  `token_verificacion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_expiracion` timestamp NULL DEFAULT NULL,
  `intentos_login` int(11) DEFAULT 0,
  `bloqueado_hasta` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de usuarios principales del sistema.';

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `nickname`, `nombre_user`, `password`, `correo`, `telefono`, `recordar_usuario`, `correo_verificado`, `telefono_verificado`, `estado_cuenta`, `fecha_registro`, `fecha_creacion`, `ultimo_acceso`, `token_verificacion`, `token_expiracion`, `intentos_login`, `bloqueado_hasta`) VALUES
(1, 'el_cliente_oro', 'Ana López García', '$2y$10$wE98T7W/X8/hS.g7.5A.b.uV8J3R3Q5R4Z6/T4I5L8N5R8S7K9B6.C5A', 'ana.lopez@email.com', '5511223344', 0, 1, 0, 'activo', '2025-10-30 05:03:37', '2025-10-30 05:03:37', '2025-10-30 05:03:37', NULL, NULL, 0, NULL),
(2, 'juan_sin_verificar', 'Juan Pérez M.', '$2y$10$wE98T7W/X8/hS.g7.5A.b.uV8J3R3Q5R4Z6/T4I5L8N5R8S7K9B6.C5A', 'juan.perez@email.com', '5599887766', 0, 0, 0, 'inactivo', '2025-10-30 05:03:37', '2025-10-30 05:03:37', NULL, NULL, NULL, 0, NULL),
(3, 'marta_la_rapida', 'Marta Gómez R.', '$2y$10$wE98T7W/X8/hS.g7.5A.b.uV8J3R3Q5R4Z6/T4I5L8N5R8S7K9B6.C5A', 'marta.gomez@email.com', '5544556677', 0, 1, 0, 'activo', '2025-10-30 05:03:37', '2025-10-30 05:03:37', '2025-10-30 05:03:37', NULL, NULL, 0, NULL),
(4, 'pedro_bloqueado', 'Pedro Martínez A.', '$2y$10$wE98T7W/X8/hS.g7.5A.b.uV8J3R3Q5R4Z6/T4I5L8N5R8S7K9B6.C5A', 'pedro.m@email.com', '5510101010', 0, 1, 0, 'activo', '2025-10-30 05:03:37', '2025-10-30 05:03:37', NULL, NULL, NULL, 0, NULL);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `administradores`
--
ALTER TABLE `administradores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_admin_correo` (`correo`);

--
-- Indices de la tabla `asistencia`
--
ALTER TABLE `asistencia`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_asistencia_tecnico_idx` (`id_tecnico`),
  ADD KEY `fk_asistencia_usuario_idx` (`id_usuario`);

--
-- Indices de la tabla `codigos_verificacion`
--
ALTER TABLE `codigos_verificacion`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_codigos_usuario_idx` (`usuario_id`),
  ADD KEY `idx_usuario_codigo` (`usuario_id`,`codigo`),
  ADD KEY `idx_expiracion` (`fecha_expiracion`);

--
-- Indices de la tabla `contratos`
--
ALTER TABLE `contratos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_contratos_usuario_idx` (`id_usuario`),
  ADD KEY `fk_contratos_administrador_idx` (`id_administrador`),
  ADD KEY `fk_contratos_paquete_idx` (`id_paquete`),
  ADD KEY `fk_contratos_promocion_idx` (`id_promocion`);

--
-- Indices de la tabla `logs_actividad`
--
ALTER TABLE `logs_actividad`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_logs_usuario_idx` (`usuario_id`),
  ADD KEY `idx_fecha` (`fecha`),
  ADD KEY `idx_accion` (`accion`);

--
-- Indices de la tabla `pagos`
--
ALTER TABLE `pagos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pagos_contrato_idx` (`id_contrato`);

--
-- Indices de la tabla `pagos_detalles`
--
ALTER TABLE `pagos_detalles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_detalle_pago_idx` (`id_pago`);

--
-- Indices de la tabla `paquetes_internet`
--
ALTER TABLE `paquetes_internet`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `promociones_temporales`
--
ALTER TABLE `promociones_temporales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_promociones_paquete_idx` (`id_paquete`);

--
-- Indices de la tabla `sesiones`
--
ALTER TABLE `sesiones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_token_sesion` (`token_sesion`),
  ADD KEY `fk_sesiones_usuario_idx` (`usuario_id`),
  ADD KEY `idx_expiracion` (`fecha_expiracion`);

--
-- Indices de la tabla `tecnicos`
--
ALTER TABLE `tecnicos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tecnico_correo` (`correo`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_nickname` (`nickname`),
  ADD UNIQUE KEY `uq_correo` (`correo`),
  ADD UNIQUE KEY `uq_telefono` (`telefono`),
  ADD KEY `idx_nickname` (`nickname`),
  ADD KEY `idx_correo` (`correo`),
  ADD KEY `idx_telefono` (`telefono`),
  ADD KEY `idx_token` (`token_verificacion`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `administradores`
--
ALTER TABLE `administradores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `asistencia`
--
ALTER TABLE `asistencia`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `codigos_verificacion`
--
ALTER TABLE `codigos_verificacion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `contratos`
--
ALTER TABLE `contratos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `logs_actividad`
--
ALTER TABLE `logs_actividad`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `pagos`
--
ALTER TABLE `pagos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `pagos_detalles`
--
ALTER TABLE `pagos_detalles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `paquetes_internet`
--
ALTER TABLE `paquetes_internet`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `promociones_temporales`
--
ALTER TABLE `promociones_temporales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `sesiones`
--
ALTER TABLE `sesiones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tecnicos`
--
ALTER TABLE `tecnicos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `asistencia`
--
ALTER TABLE `asistencia`
  ADD CONSTRAINT `fk_asistencia_tecnico` FOREIGN KEY (`id_tecnico`) REFERENCES `tecnicos` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_asistencia_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `codigos_verificacion`
--
ALTER TABLE `codigos_verificacion`
  ADD CONSTRAINT `fk_codigos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `contratos`
--
ALTER TABLE `contratos`
  ADD CONSTRAINT `fk_contratos_administrador` FOREIGN KEY (`id_administrador`) REFERENCES `administradores` (`id`),
  ADD CONSTRAINT `fk_contratos_paquete` FOREIGN KEY (`id_paquete`) REFERENCES `paquetes_internet` (`id`),
  ADD CONSTRAINT `fk_contratos_promocion` FOREIGN KEY (`id_promocion`) REFERENCES `promociones_temporales` (`id`),
  ADD CONSTRAINT `fk_contratos_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `logs_actividad`
--
ALTER TABLE `logs_actividad`
  ADD CONSTRAINT `fk_logs_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `pagos`
--
ALTER TABLE `pagos`
  ADD CONSTRAINT `fk_pagos_contrato` FOREIGN KEY (`id_contrato`) REFERENCES `contratos` (`id`);

--
-- Filtros para la tabla `pagos_detalles`
--
ALTER TABLE `pagos_detalles`
  ADD CONSTRAINT `fk_detalle_pago` FOREIGN KEY (`id_pago`) REFERENCES `pagos` (`id`);

--
-- Filtros para la tabla `promociones_temporales`
--
ALTER TABLE `promociones_temporales`
  ADD CONSTRAINT `fk_promociones_paquete` FOREIGN KEY (`id_paquete`) REFERENCES `paquetes_internet` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `sesiones`
--
ALTER TABLE `sesiones`
  ADD CONSTRAINT `fk_sesiones_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
