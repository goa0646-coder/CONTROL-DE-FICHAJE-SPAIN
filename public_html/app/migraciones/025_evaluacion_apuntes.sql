-- Evaluación privada: 5 apartados subjetivos por valoración y apuntes (incidencia, mala conducta, amonestación).
ALTER TABLE valoraciones ADD trato TINYINT UNSIGNED NULL, ADD tecnica TINYINT UNSIGNED NULL, ADD equipo TINYINT UNSIGNED NULL,
  ADD iniciativa TINYINT UNSIGNED NULL, ADD actitud TINYINT UNSIGNED NULL;
CREATE TABLE apuntes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  tipo ENUM('incidencia','mala_conducta','amonestacion') NOT NULL,
  texto VARCHAR(1000) NOT NULL,
  fecha DATE NOT NULL,
  autor_id INT UNSIGNED NOT NULL,
  creado_utc DATETIME NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (autor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
