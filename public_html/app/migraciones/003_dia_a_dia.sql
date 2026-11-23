-- Fase 2: motivos de pausa, departamentos, horarios, festivos, saldo de horas, avisos y límites de fichaje.
CREATE TABLE motivos_pausa (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  retribuida TINYINT(1) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO motivos_pausa (nombre, retribuida) VALUES ('Comida', 0), ('Descanso', 0), ('Médico', 1), ('Asunto personal', 0);

ALTER TABLE fichajes ADD motivo_id INT UNSIGNED NULL AFTER origen, ADD FOREIGN KEY (motivo_id) REFERENCES motivos_pausa(id);

CREATE TABLE departamentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE horarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  flexible TINYINT(1) NOT NULL DEFAULT 0,
  margen_min SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- dia: 1 = lunes … 7 = domingo. Sin fila = día libre.
CREATE TABLE horario_dias (
  horario_id INT UNSIGNED NOT NULL,
  dia TINYINT UNSIGNED NOT NULL,
  entrada TIME NULL,
  salida TIME NULL,
  minutos SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (horario_id, dia),
  FOREIGN KEY (horario_id) REFERENCES horarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE usuarios ADD departamento_id INT UNSIGNED NULL, ADD horario_id INT UNSIGNED NULL,
  ADD FOREIGN KEY (departamento_id) REFERENCES departamentos(id), ADD FOREIGN KEY (horario_id) REFERENCES horarios(id);

-- Horario de un día concreto (cambios planificados y turnos). horario_id NULL = libre.
CREATE TABLE planificacion (
  usuario_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  horario_id INT UNSIGNED NULL,
  PRIMARY KEY (usuario_id, fecha),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (horario_id) REFERENCES horarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- centro_id NULL = festivo para toda la empresa.
CREATE TABLE festivos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  centro_id INT UNSIGNED NULL,
  fecha DATE NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  FOREIGN KEY (centro_id) REFERENCES centros(id),
  INDEX (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE centros ADD fuera_zona ENUM('aceptar','rechazar') NOT NULL DEFAULT 'aceptar', ADD ips VARCHAR(500) NOT NULL DEFAULT '';

ALTER TABLE empresa ADD horas_anuales SMALLINT UNSIGNED NULL;

-- Movimientos manuales de la bolsa de horas (horas extra pagadas, compensaciones…). Solo se añaden.
CREATE TABLE ajustes_saldo (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  minutos INT NOT NULL,
  motivo VARCHAR(200) NOT NULL,
  creado_por INT UNSIGNED NOT NULL,
  creado_utc DATETIME NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- clave evita avisar dos veces de lo mismo.
CREATE TABLE avisos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  clave VARCHAR(100) NOT NULL,
  texto VARCHAR(500) NOT NULL,
  enlace VARCHAR(200) NOT NULL DEFAULT '',
  creado_utc DATETIME NOT NULL,
  leido_utc DATETIME NULL,
  UNIQUE (usuario_id, clave),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
