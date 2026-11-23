-- Fase 4: resumen mensual firmado por la persona. Solo se añaden filas.
CREATE TABLE firmas_mensuales (
  usuario_id INT UNSIGNED NOT NULL,
  mes CHAR(7) NOT NULL,
  hash CHAR(64) NOT NULL,
  firmado_utc DATETIME NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  PRIMARY KEY (usuario_id, mes, hash),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TRIGGER firmas_no_update BEFORE UPDATE ON firmas_mensuales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las firmas no se pueden modificar';
CREATE TRIGGER firmas_no_delete BEFORE DELETE ON firmas_mensuales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las firmas no se pueden borrar';
