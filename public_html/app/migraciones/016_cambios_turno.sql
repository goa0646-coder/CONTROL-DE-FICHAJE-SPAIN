-- Cambios de turno entre compañeros: lo pide uno, acepta el otro y lo aprueba administración o su responsable.
CREATE TABLE cambios_turno (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitante_id INT UNSIGNED NOT NULL,
  companero_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  fecha_devuelve DATE NULL,
  motivo VARCHAR(500) NOT NULL DEFAULT '',
  estado ENUM('companero','aprobacion','aprobado','rechazado','cancelado') NOT NULL DEFAULT 'companero',
  pedido_utc DATETIME NOT NULL,
  respondido_utc DATETIME NULL,
  resuelto_por INT UNSIGNED NULL,
  resuelto_utc DATETIME NULL,
  comentario VARCHAR(500) NOT NULL DEFAULT '',
  FOREIGN KEY (solicitante_id) REFERENCES usuarios(id),
  FOREIGN KEY (companero_id) REFERENCES usuarios(id),
  FOREIGN KEY (resuelto_por) REFERENCES usuarios(id),
  INDEX (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
