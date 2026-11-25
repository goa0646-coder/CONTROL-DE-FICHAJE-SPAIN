-- Pausa obligatoria (no retribuida) de un turno, p. ej. las 2 horas de comida del partido. Las horas del turno ya la descuentan.
ALTER TABLE horarios ADD pausa_min SMALLINT UNSIGNED NOT NULL DEFAULT 0;
UPDATE horarios SET pausa_min = 120 WHERE nombre = 'COORDINACIÓN PARTIDO';
UPDATE horario_dias d JOIN horarios h ON h.id = d.horario_id AND h.nombre = 'COORDINACIÓN PARTIDO' AND d.entrada = '09:00:00' AND d.salida = '20:00:00' SET d.minutos = 540;
