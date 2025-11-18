-- Base de datos para Hermes Express
-- Eliminar la base de datos si existe (¡cuidado en producción!)
DROP DATABASE IF EXISTS hermes_express;

-- Crear la base de datos
CREATE DATABASE hermes_express;
USE hermes_express;

-- Tabla de rutas
CREATE TABLE IF NOT EXISTS rutas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    zonas TEXT,
    origen VARCHAR(100) NULL,
    destino VARCHAR(100) NULL,
    distancia DECIMAL(8,2) DEFAULT 0,
    tiempo_estimado INT DEFAULT 0,
    activa BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar rutas predefinidas
INSERT INTO rutas (id, nombre, zonas) VALUES
(1, 'URBANO', 'Chiclayo, Leonardo Ortiz, La Victoria, Santa Victoria'),
(2, 'PUEBLOS', 'Lambayeque, Mochumi, Tucume, Illimo, Nueva Arica, Jayanca, Pacora, Morrope, Motupe, Olmos, Salas'),
(3, 'PLAYAS', 'San Jose, Santa Rosa, Pimentel, Reque, Monsefu, Eten, Puerto Eten'),
(4, 'COOPERATIVAS', 'Pomalca, Tuman, Patapo, Pucala, Saltur, Chongoyape'),
(5, 'EXCOOPERATIVA', 'Ucupe, Mocupe, Zaña, Cayalti, Oyotun, Lagunas');

-- Tabla de usuarios
CREATE TABLE IF NOT EXISTS usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario VARCHAR(50) UNIQUE NOT NULL,
    clave VARCHAR(255) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    tipo ENUM('admin', 'asistente', 'empleado') NOT NULL,
    activo BOOLEAN DEFAULT TRUE,
    ruta_id INT NULL,
    distrito VARCHAR(100) NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ruta_id) REFERENCES rutas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar administrador por defecto (contraseña: admin123)
INSERT INTO usuarios (usuario, clave, nombre, email, tipo, activo) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador', 'admin@hermesexpress.com', 'admin', 1);

-- Insertar empleados de ejemplo con rutas asignadas
INSERT INTO usuarios (usuario, clave, nombre, email, tipo, activo, ruta_id, distrito) VALUES
('empleado1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Empleado Urbano', 'urbano@ejemplo.com', 'empleado', 1, 1, 'Chiclayo'),
('empleado2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Empleado Pueblos', 'pueblos@ejemplo.com', 'empleado', 1, 2, 'Lambayeque'),
('empleado3', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Empleado Playas', 'playas@ejemplo.com', 'empleado', 1, 3, 'Pimentel');

-- Tabla de vehículos
CREATE TABLE IF NOT EXISTS vehiculos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    placa VARCHAR(20) UNIQUE NOT NULL,
    marca VARCHAR(50) NOT NULL,
    modelo VARCHAR(50) NOT NULL,
    capacidad DECIMAL(8,2) NOT NULL,
    estado ENUM('disponible', 'en_ruta', 'mantenimiento') DEFAULT 'disponible',
    empleado_id INT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empleado_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de paquetes
CREATE TABLE IF NOT EXISTS paquetes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    codigo VARCHAR(20) UNIQUE NOT NULL,
    remitente VARCHAR(100) NOT NULL,
    destinatario VARCHAR(100) NOT NULL,
    direccion_origen TEXT NOT NULL,
    direccion_destino TEXT NOT NULL,
    distrito VARCHAR(100) NOT NULL,
    peso DECIMAL(8,2) NOT NULL,
    estado ENUM('pendiente', 'en_transito', 'entregado', 'devuelto') DEFAULT 'pendiente',
    precio DECIMAL(10,2) NOT NULL,
    fecha_envio DATE NOT NULL,
    fecha_entrega DATE NULL,
    empleado_id INT NULL,
    notas TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empleado_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de asignaciones de paquetes
CREATE TABLE IF NOT EXISTS asignaciones_paquetes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    paquete_id INT NOT NULL,
    empleado_id INT NOT NULL,
    fecha_asignacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('asignado', 'en_ruta', 'entregado', 'fallido') DEFAULT 'asignado',
    observaciones TEXT,
    FOREIGN KEY (paquete_id) REFERENCES paquetes(id) ON DELETE CASCADE,
    FOREIGN KEY (empleado_id) REFERENCES usuarios(id),
    UNIQUE KEY uk_paquete (paquete_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de tarifas
CREATE TABLE IF NOT EXISTS tarifas_rutas (
    tipo_ruta VARCHAR(50) PRIMARY KEY,
    tarifa_base DECIMAL(10,2) NOT NULL,
    tarifa_por_kg DECIMAL(10,2) NOT NULL,
    comision_empleado DECIMAL(5,2) NOT NULL,
    descripcion TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar tarifas de ejemplo
INSERT INTO tarifas_rutas (tipo_ruta, tarifa_base, tarifa_por_kg, comision_empleado, descripcion) VALUES
('URBANO', 8.00, 1.50, 2.00, 'Zonas urbanas de Chiclayo'),
('PUEBLOS', 12.00, 2.00, 3.00, 'Pueblos cercanos a Chiclayo'),
('PLAYAS', 15.00, 2.50, 3.50, 'Playas del distrito'),
('COOPERATIVAS', 10.00, 1.80, 2.50, 'Zonas de cooperativas cercanas'),
('EXCOOPERATIVA', 18.00, 3.00, 4.00, 'Zonas de ex cooperativas');

-- Insertar paquete de prueba
INSERT INTO paquetes (codigo, remitente, destinatario, direccion_origen, direccion_destino, distrito, peso, estado, precio, fecha_envio, empleado_id)
VALUES ('PKG-001', 'Tienda ABC', 'Juan Pérez', 'Av. Balta 123', 'Calle Los Pinos 456', 'Chiclayo', 2.5, 'pendiente', 15.00, CURDATE(), NULL);
