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

-- Tabla de rutas (modificada para incluir zonas)
CREATE TABLE rutas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    zonas TEXT NOT NULL,
    origen VARCHAR(100) NULL,
    destino VARCHAR(100) NULL,
    distancia DECIMAL(8,2) DEFAULT 0,
    tiempo_estimado INT DEFAULT 0,
    activa BOOLEAN DEFAULT TRUE,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insertar datos de la imagen en la tabla rutas
INSERT INTO rutas (id, nombre, zonas)
VALUES
(4, 'COOPERATIVAS', 'Pomalca, Tuman, Patapo, Pucala, Saltur, Chongoyape'),
(5, 'EXCOOPERATIVA', 'Ucupe, Mocupe, Zaña, Cayalti, Oyotun, Lagunas'),
(3, 'PLAYAS', 'San Jose, Santa Rosa, Pimentel, Reque, Monsefu, Eten, Puerto Eten'),
(2, 'PUEBLOS', 'Lambayeque, Mochumi, Tucume, Illimo, Nueva Arica, Jayanca, Pacora, Morrope, Motupe, Olmos, Salas'),
(1, 'URBANO', 'Chiclayo, Leonardo Ortiz, La Victoria, Santa Victoria');

-- Tabla de vehículos
CREATE TABLE vehiculos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    placa VARCHAR(20) UNIQUE NOT NULL,
    marca VARCHAR(50) NOT NULL,
    modelo VARCHAR(50) NOT NULL,
    capacidad DECIMAL(8,2) NOT NULL,
    estado ENUM('disponible', 'en_ruta', 'mantenimiento') DEFAULT 'disponible',
    empleado_id INT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empleado_id) REFERENCES usuarios(id)
);

-- Tabla de paquetes
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

-- Insertar administrador
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
