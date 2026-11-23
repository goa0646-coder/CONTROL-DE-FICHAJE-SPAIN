-- Reglas de convenio, puestos y necesidades por turno, horas extra planificadas, ofertas de turno y códigos de un solo uso.
ALTER TABLE tipos_ausencia ADD computo ENUM('laborables','naturales') NOT NULL DEFAULT 'laborables';

CREATE TABLE puestos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Qué puestos puede cubrir cada persona (polivalencia).
CREATE TABLE usuarios_puestos (
  usuario_id INT UNSIGNED NOT NULL,
  puesto_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (usuario_id, puesto_id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (puesto_id) REFERENCES puestos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cuántas personas hacen falta en un turno (horario) y puesto. dia 0 = todos los días, 1–7 = lunes–domingo.
CREATE TABLE necesidades (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  centro_id INT UNSIGNED NULL,
  horario_id INT UNSIGNED NOT NULL,
  puesto_id INT UNSIGNED NULL,
  dia TINYINT UNSIGNED NOT NULL DEFAULT 0,
  personas TINYINT UNSIGNED NOT NULL,
  FOREIGN KEY (centro_id) REFERENCES centros(id),
  FOREIGN KEY (horario_id) REFERENCES horarios(id),
  FOREIGN KEY (puesto_id) REFERENCES puestos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- extra = 1: turno aceptado como horas extra (no suma a las horas teóricas). fuerza_mayor: no cuenta en el tope anual (art. 35.3 ET).
ALTER TABLE planificacion ADD puesto_id INT UNSIGNED NULL, ADD extra TINYINT(1) NOT NULL DEFAULT 0, ADD fuerza_mayor TINYINT(1) NOT NULL DEFAULT 0,
  ADD FOREIGN KEY (puesto_id) REFERENCES puestos(id);

-- Turno ofrecido a una persona (cubrir una baja como horas extra). Se acepta con un código de un solo uso.
CREATE TABLE ofertas_turno (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  horario_id INT UNSIGNED NOT NULL,
  puesto_id INT UNSIGNED NULL,
  motivo VARCHAR(200) NOT NULL DEFAULT '',
  fuerza_mayor TINYINT(1) NOT NULL DEFAULT 0,
  estado ENUM('pendiente','aceptada','rechazada','anulada') NOT NULL DEFAULT 'pendiente',
  creado_por INT UNSIGNED NOT NULL,
  creado_utc DATETIME NOT NULL,
  respondida_utc DATETIME NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  metodo VARCHAR(20) NOT NULL DEFAULT '',
  hash CHAR(64) NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (horario_id) REFERENCES horarios(id),
  FOREIGN KEY (puesto_id) REFERENCES puestos(id),
  FOREIGN KEY (creado_por) REFERENCES usuarios(id),
  INDEX (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE codigos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  proposito VARCHAR(60) NOT NULL,
  hash CHAR(64) NOT NULL,
  expira_utc DATETIME NOT NULL,
  intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  INDEX (usuario_id, proposito)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
