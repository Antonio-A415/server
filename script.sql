-- Base de datos para sistema de login con verificación
CREATE DATABASE IF NOT EXISTS app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE app;

-- Tabla de usuarios
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nickname VARCHAR(50) UNIQUE NOT NULL,
    nombre_user VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL, -- Hash de la contraseña
    correo VARCHAR(100) UNIQUE,
    telefono VARCHAR(20) UNIQUE,
    recordar_usuario BOOLEAN DEFAULT FALSE,
    correo_verificado BOOLEAN DEFAULT FALSE,
    telefono_verificado BOOLEAN DEFAULT FALSE,
    estado_cuenta ENUM('activo', 'inactivo', 'suspendido') DEFAULT 'inactivo',
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ultimo_acceso TIMESTAMP NULL,
    token_verificacion VARCHAR(255), -- Token para verificación
    token_expiracion TIMESTAMP NULL,
    intentos_login INT DEFAULT 0,
    bloqueado_hasta TIMESTAMP NULL,
    INDEX idx_nickname (nickname),
    INDEX idx_correo (correo),
    INDEX idx_telefono (telefono),
    INDEX idx_token (token_verificacion)
);

-- Tabla de códigos de verificación
CREATE TABLE codigos_verificacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    codigo VARCHAR(10) NOT NULL,
    tipo_verificacion ENUM('correo', 'whatsapp') NOT NULL,
    usado BOOLEAN DEFAULT FALSE,
    intentos INT DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_expiracion TIMESTAMP NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_usuario_codigo (usuario_id, codigo),
    INDEX idx_expiracion (fecha_expiracion)
);

-- Tabla de sesiones (opcional, para manejo avanzado de sesiones)
CREATE TABLE sesiones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token_sesion VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    fecha_inicio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_expiracion TIMESTAMP NOT NULL,
    activa BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_token (token_sesion),
    INDEX idx_usuario (usuario_id),
    INDEX idx_expiracion (fecha_expiracion)
);

-- Tabla de logs de actividad
CREATE TABLE logs_actividad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT,
    accion VARCHAR(50) NOT NULL,
    detalles TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_usuario (usuario_id),
    INDEX idx_fecha (fecha),
    INDEX idx_accion (accion)
);

-- Insertar usuario administrador de ejemplo (contraseña: admin123)
INSERT INTO usuarios (nickname, nombre_user, password, correo, correo_verificado, estado_cuenta) 
VALUES ('admin', 'Administrador', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@tuapp.com', TRUE, 'activo');

-- Procedimientos almacenados útiles

DELIMITER //

-- Limpiar códigos de verificación expirados
CREATE PROCEDURE LimpiarCodigosExpirados()
BEGIN
    DELETE FROM codigos_verificacion 
    WHERE fecha_expiracion < NOW() OR intentos >= 3;
END //

-- Limpiar sesiones expiradas
CREATE PROCEDURE LimpiarSesionesExpiradas()
BEGIN
    UPDATE sesiones SET activa = FALSE 
    WHERE fecha_expiracion < NOW();
    
    DELETE FROM sesiones 
    WHERE fecha_expiracion < DATE_SUB(NOW(), INTERVAL 30 DAY);
END //

-- Verificar y desbloquear usuarios
CREATE PROCEDURE DesbloquearUsuarios()
BEGIN
    UPDATE usuarios 
    SET intentos_login = 0, bloqueado_hasta = NULL 
    WHERE bloqueado_hasta IS NOT NULL AND bloqueado_hasta < NOW();
END //

DELIMITER ;

-- Eventos para limpieza automática (opcional)
SET GLOBAL event_scheduler = ON;

CREATE EVENT IF NOT EXISTS limpieza_codigos
ON SCHEDULE EVERY 1 HOUR
DO
  CALL LimpiarCodigosExpirados();

CREATE EVENT IF NOT EXISTS limpieza_sesiones
ON SCHEDULE EVERY 6 HOUR
DO
  CALL LimpiarSesionesExpiradas();

CREATE EVENT IF NOT EXISTS desbloqueo_usuarios
ON SCHEDULE EVERY 15 MINUTE
DO
  CALL DesbloquearUsuarios();


-- Actualizar la tabla para soportar reset_password
ALTER TABLE codigos_verificacion 
MODIFY COLUMN tipo_verificacion ENUM('correo', 'whatsapp', 'reset_password') NOT NULL;

-- También asegúrate de que el campo token_verificacion en usuarios tenga suficiente espacio
ALTER TABLE usuarios 
MODIFY COLUMN token_verificacion VARCHAR(255);

-- Fin del script