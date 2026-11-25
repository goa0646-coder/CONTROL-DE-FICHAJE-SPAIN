-- Preferencias de cada trabajador: turnos, días de la semana o fechas que prefiere o quiere evitar.
-- Son sugerencias para el generador (nunca una regla): se cumplen si caben con los ratios y el convenio.
CREATE TABLE preferencias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  tipo ENUM('turno','dia','fecha') NOT NULL,
  horario_id INT UNSIGNED NULL,
  dia TINYINT UNSIGNED NULL,
  fecha DATE NULL,
  prefiere TINYINT(1) NOT NULL DEFAULT 1,
  comentario VARCHAR(300) NOT NULL DEFAULT '',
  creada_utc DATETIME NOT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (horario_id) REFERENCES horarios(id),
  INDEX (usuario_id, activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
