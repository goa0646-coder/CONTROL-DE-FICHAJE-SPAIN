-- Pausas que necesitan justificante (todas menos comida y descanso) y los justificantes que sube cada persona.
-- Los fichajes no se tocan: el justificante va en su propia tabla.
ALTER TABLE motivos_pausa ADD justificante TINYINT(1) NOT NULL DEFAULT 0;
UPDATE motivos_pausa SET justificante = 1 WHERE nombre NOT IN ('Comida', 'Descanso');
CREATE TABLE justificantes_pausa (
  fichaje_id BIGINT UNSIGNED PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  archivo VARCHAR(100) NOT NULL,
  nombre_original VARCHAR(200) NOT NULL DEFAULT '',
  subido_utc DATETIME NOT NULL,
  FOREIGN KEY (fichaje_id) REFERENCES fichajes(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
