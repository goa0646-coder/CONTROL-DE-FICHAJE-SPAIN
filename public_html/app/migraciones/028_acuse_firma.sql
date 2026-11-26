-- Anuncios con firma requerida: DNI, firma manuscrita en pantalla, casilla de aceptación y certificado PDF con las evidencias.
ALTER TABLE comunicados ADD firma_requerida TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE acuses_firma (
  comunicado_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  firmado_utc DATETIME NOT NULL,
  dni VARCHAR(255) NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  agente VARCHAR(300) NOT NULL DEFAULT '',
  sesion CHAR(64) NOT NULL DEFAULT '',
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  precision_m INT UNSIGNED NULL,
  hash_texto CHAR(64) NOT NULL,
  hash_firma CHAR(64) NOT NULL,
  documento_id INT UNSIGNED NOT NULL,
  hash_pdf CHAR(64) NOT NULL,
  PRIMARY KEY (comunicado_id, usuario_id),
  FOREIGN KEY (comunicado_id) REFERENCES comunicados(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (documento_id) REFERENCES documentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
