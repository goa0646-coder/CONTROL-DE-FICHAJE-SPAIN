CREATE TABLE empresa (
  id TINYINT UNSIGNED PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  cif VARCHAR(20) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE centros (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  direccion VARCHAR(200) NOT NULL DEFAULT '',
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  radio_m INT UNSIGNED NOT NULL DEFAULT 150,
  gps_modo ENUM('no','opcional','obligatorio') NOT NULL DEFAULT 'no',
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  num_empleado VARCHAR(20) NULL UNIQUE,
  nombre VARCHAR(80) NOT NULL,
  apellidos VARCHAR(120) NOT NULL DEFAULT '',
  dni VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash VARCHAR(255) NULL,
  rol ENUM('empleado','responsable','admin','inspeccion') NOT NULL DEFAULT 'empleado',
  centro_id INT UNSIGNED NULL,
  responsable_id INT UNSIGNED NULL,
  gps_modo ENUM('no','opcional','obligatorio') NULL,
  aviso_gps_utc DATETIME NULL,
  acceso_hasta DATE NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  alta_utc DATETIME NOT NULL,
  baja_utc DATETIME NULL,
  reset_hash CHAR(64) NULL,
  reset_expira_utc DATETIME NULL,
  FOREIGN KEY (centro_id) REFERENCES centros(id),
  FOREIGN KEY (responsable_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quioscos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  centro_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  alta_utc DATETIME NOT NULL,
  FOREIGN KEY (centro_id) REFERENCES centros(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE correcciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  accion ENUM('anadir','anular','cambiar') NOT NULL,
  fichaje_id BIGINT UNSIGNED NULL,
  tipo_propuesto ENUM('entrada','pausa_inicio','pausa_fin','salida') NULL,
  momento_propuesto_utc DATETIME NULL,
  motivo VARCHAR(500) NOT NULL,
  estado ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
  pedida_por INT UNSIGNED NOT NULL,
  pedida_utc DATETIME NOT NULL,
  resuelta_por INT UNSIGNED NULL,
  resuelta_utc DATETIME NULL,
  comentario VARCHAR(500) NOT NULL DEFAULT '',
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (pedida_por) REFERENCES usuarios(id),
  FOREIGN KEY (resuelta_por) REFERENCES usuarios(id),
  INDEX (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fichajes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  tipo ENUM('entrada','pausa_inicio','pausa_fin','salida') NOT NULL,
  momento_utc DATETIME NOT NULL,
  origen ENUM('movil','web','quiosco','correccion') NOT NULL,
  centro_id INT UNSIGNED NULL,
  quiosco_id INT UNSIGNED NULL,
  zona ENUM('dentro','fuera') NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  correccion_id INT UNSIGNED NULL,
  creado_utc DATETIME NOT NULL,
  hash_anterior CHAR(64) NULL,
  hash CHAR(64) NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (centro_id) REFERENCES centros(id),
  FOREIGN KEY (quiosco_id) REFERENCES quioscos(id),
  FOREIGN KEY (correccion_id) REFERENCES correcciones(id),
  INDEX (usuario_id, momento_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE correcciones ADD FOREIGN KEY (fichaje_id) REFERENCES fichajes(id);

-- Coordenadas aparte: se borran a los 12 meses sin tocar el fichaje (que es inmutable).
CREATE TABLE fichajes_gps (
  fichaje_id BIGINT UNSIGNED PRIMARY KEY,
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  precision_m INT UNSIGNED NULL,
  FOREIGN KEY (fichaje_id) REFERENCES fichajes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auditoria (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  accion VARCHAR(50) NOT NULL,
  detalle VARCHAR(1000) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  momento_utc DATETIME NOT NULL,
  INDEX (momento_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE intentos_login (
  clave VARCHAR(190) NOT NULL,
  momento_utc DATETIME NOT NULL,
  INDEX (clave, momento_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
