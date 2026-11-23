-- Fase 7: QR, API/extensión/chat, permisos por rol.
ALTER TABLE fichajes MODIFY origen ENUM('movil','web','quiosco','correccion','qr','api','chat') NOT NULL;

CREATE TABLE tokens_api (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  alcance ENUM('personal','lectura') NOT NULL DEFAULT 'personal',
  chat_id VARCHAR(120) NULL UNIQUE,
  creado_utc DATETIME NOT NULL,
  usado_utc DATETIME NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permisos extra que el admin da a cada rol (el admin los tiene todos).
CREATE TABLE permisos_rol (
  rol ENUM('empleado','responsable','inspeccion') NOT NULL,
  permiso VARCHAR(40) NOT NULL,
  PRIMARY KEY (rol, permiso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permisos_rol VALUES ('responsable', 'informes'), ('responsable', 'planificar'), ('inspeccion', 'informes');
