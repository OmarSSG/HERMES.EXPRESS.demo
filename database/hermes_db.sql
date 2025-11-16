-- Base de datos para Hermes Express
CREATE DATABASE IF NOT EXISTS hermes_express;
USE hermes_express;

-- Tabla de usuarios
CREATE TABLE usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario VARCHAR(50) UNIQUE NOT NULL,
    clave VARCHAR(255) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    tipo ENUM('admin', 'asistente', 'empleado') NOT NULL,
    activo BOOLEAN DEFAULT TRUE,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de rutas
CREATE TABLE rutas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    origen VARCHAR(100) NOT NULL,
    destino VARCHAR(100) NOT NULL,
    distancia DECIMAL(8,2) NOT NULL,
    tiempo_estimado INT NOT NULL, -- en minutos
    activa BOOLEAN DEFAULT TRUE,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de vehículos
CREATE TABLE vehiculos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    placa VARCHAR(20) UNIQUE NOT NULL,
    marca VARCHAR(50) NOT NULL,
    modelo VARCHAR(50) NOT NULL,
    capacidad DECIMAL(8,2) NOT NULL, -- en kg
    estado ENUM('disponible', 'en_ruta', 'mantenimiento') DEFAULT 'disponible',
    empleado_id INT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empleado_id) REFERENCES usuarios(id)
);

-- Tabla de paquetes (sin tipo_ruta inicialmente)
CREATE TABLE paquetes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    codigo VARCHAR(20) UNIQUE NOT NULL,
    remitente VARCHAR(100) NOT NULL,
    destinatario VARCHAR(100) NOT NULL,
    direccion_origen TEXT NOT NULL,
    direccion_destino TEXT NOT NULL,
    peso DECIMAL(8,2) NOT NULL,
    estado ENUM('pendiente', 'en_transito', 'entregado', 'devuelto') DEFAULT 'pendiente',
    precio DECIMAL(10,2) NOT NULL,
    fecha_envio DATE NOT NULL,
    fecha_entrega DATE NULL,
    empleado_id INT,
    notas TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empleado_id) REFERENCES usuarios(id)
);

-- Insertar solo el usuario administrador por defecto
-- Contraseña: admin123
INSERT INTO usuarios (usuario, clave, nombre, email, tipo) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador Principal', 'admin@hermesexpress.com', 'admin');

-- Tabla tarifas_rutas
CREATE TABLE tarifas_rutas (
    tipo_ruta VARCHAR(50) PRIMARY KEY,
    tarifa_base DECIMAL(10,2) NOT NULL,
    tarifa_por_kg DECIMAL(10,2) NOT NULL,
    comision_empleado DECIMAL(5,2) NOT NULL,
    descripcion TEXT
);
