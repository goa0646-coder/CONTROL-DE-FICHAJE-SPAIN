-- Cambios de turno que incumplen el convenio y los dos aceptan por su propia voluntad (queda el motivo y la aceptación de cada uno).
ALTER TABLE cambios_turno
  ADD incumple VARCHAR(500) NOT NULL DEFAULT '',
  ADD voluntario_companero TINYINT(1) NOT NULL DEFAULT 0;
