-- Contratos de cada persona (historial editable): modalidad, categoría, jornada, inicio, fin y convenio.
-- No se borran: un contrato metido por error se marca como anulado.
CREATE TABLE contratos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(80) NOT NULL DEFAULT '',
  categoria VARCHAR(100) NOT NULL DEFAULT '',
  jornada VARCHAR(60) NOT NULL DEFAULT '',
  inicio DATE NOT NULL,
  fin DATE NULL,
  convenio VARCHAR(200) NOT NULL DEFAULT '',
  notas VARCHAR(500) NOT NULL DEFAULT '',
  anulado TINYINT(1) NOT NULL DEFAULT 0,
  creado_por INT UNSIGNED NULL,
  creado_utc DATETIME NOT NULL,
  cambiado_utc DATETIME NULL,
  INDEX (usuario_id, inicio),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
