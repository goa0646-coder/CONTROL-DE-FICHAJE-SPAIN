-- Fase 6: documentos, comunicados y avisos push.
CREATE TABLE documentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  titulo VARCHAR(150) NOT NULL,
  archivo VARCHAR(100) NOT NULL,
  nombre_original VARCHAR(200) NOT NULL,
  requiere_firma TINYINT(1) NOT NULL DEFAULT 0,
  subido_por INT UNSIGNED NOT NULL,
  subido_utc DATETIME NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (subido_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- usuario_id NULL en documentos = para toda la plantilla. Firma = confirmación con contraseña + hash del archivo.
CREATE TABLE documentos_firmas (
  documento_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  hash CHAR(64) NOT NULL,
  firmado_utc DATETIME NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  PRIMARY KEY (documento_id, usuario_id),
  FOREIGN KEY (documento_id) REFERENCES documentos(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comunicados (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(150) NOT NULL,
  texto TEXT NOT NULL,
  centro_id INT UNSIGNED NULL,
  autor_id INT UNSIGNED NOT NULL,
  creado_utc DATETIME NOT NULL,
  FOREIGN KEY (centro_id) REFERENCES centros(id),
  FOREIGN KEY (autor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comunicados_leidos (
  comunicado_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  leido_utc DATETIME NOT NULL,
  PRIMARY KEY (comunicado_id, usuario_id),
  FOREIGN KEY (comunicado_id) REFERENCES comunicados(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE suscripciones_push (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  endpoint VARCHAR(700) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  creado_utc DATETIME NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valores internos generados por INDA (p. ej. claves VAPID para push).
CREATE TABLE ajustes (
  clave VARCHAR(50) PRIMARY KEY,
  valor TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
