-- Turnos con letra y color para el calendario (M, T, N, P…) y departamento al que pertenecen.
ALTER TABLE horarios
  ADD abreviatura VARCHAR(3) NOT NULL DEFAULT '',
  ADD color VARCHAR(7) NOT NULL DEFAULT '',
  ADD departamento_id INT UNSIGNED NULL,
  ADD FOREIGN KEY (departamento_id) REFERENCES departamentos(id);
