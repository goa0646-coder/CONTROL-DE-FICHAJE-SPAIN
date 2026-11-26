-- Anuncios con firma requerida en dos pasos: primero la firma de recepción (sin ver el contenido) y después la de lectura y aceptación.
CREATE TABLE recepciones_firma (
  comunicado_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  recibido_utc DATETIME NOT NULL,
  dni VARCHAR(255) NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  agente VARCHAR(300) NOT NULL DEFAULT '',
  sesion CHAR(64) NOT NULL DEFAULT '',
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  precision_m INT UNSIGNED NULL,
  firma_archivo VARCHAR(100) NOT NULL,
  hash_firma CHAR(64) NOT NULL,
  PRIMARY KEY (comunicado_id, usuario_id),
  FOREIGN KEY (comunicado_id) REFERENCES comunicados(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
