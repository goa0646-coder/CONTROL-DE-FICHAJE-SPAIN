-- Sesión que se mantiene en el móvil (como una app). El token solo se guarda como hash.
CREATE TABLE dispositivos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  nombre VARCHAR(150) NOT NULL DEFAULT '',
  creado_utc DATETIME NOT NULL,
  usado_utc DATETIME NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
