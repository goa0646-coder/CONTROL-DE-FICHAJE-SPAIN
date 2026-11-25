-- Otros códigos que valen para un turno al importar un Excel (p. ej. «8» = AUXILIAR MAÑANA).
ALTER TABLE horarios ADD alias VARCHAR(30) NOT NULL DEFAULT '';

-- AS FRAGAS: en su Excel antiguo 8 = mañana, 7 = tarde, 9 = noche (eran las horas para sumar) y 6 = terapia ocupacional.
-- Si existen esos turnos, se pasa lo planificado al turno de verdad y las «jornadas» se desactivan (no se borra nada).
UPDATE horarios SET alias = '8' WHERE nombre = 'AUXILIAR MAÑANA' AND EXISTS (SELECT 1 FROM (SELECT id FROM horarios WHERE nombre = 'JORNADA 8 HORAS') x);
UPDATE horarios SET alias = '7' WHERE nombre = 'AUXILIAR TARDE' AND EXISTS (SELECT 1 FROM (SELECT id FROM horarios WHERE nombre = 'JORNADA 7 HORAS') x);
UPDATE horarios SET alias = '9' WHERE nombre = 'AUXILIAR NOCHE' AND EXISTS (SELECT 1 FROM (SELECT id FROM horarios WHERE nombre = 'JORNADA 9 HORAS') x);
UPDATE planificacion p JOIN horarios j ON j.id = p.horario_id AND j.nombre = 'JORNADA 8 HORAS' JOIN horarios h ON h.nombre = 'AUXILIAR MAÑANA' SET p.horario_id = h.id;
UPDATE planificacion p JOIN horarios j ON j.id = p.horario_id AND j.nombre = 'JORNADA 7 HORAS' JOIN horarios h ON h.nombre = 'AUXILIAR TARDE' SET p.horario_id = h.id;
UPDATE planificacion p JOIN horarios j ON j.id = p.horario_id AND j.nombre = 'JORNADA 9 HORAS' JOIN horarios h ON h.nombre = 'AUXILIAR NOCHE' SET p.horario_id = h.id;
UPDATE usuarios u JOIN horarios j ON j.id = u.horario_id AND j.nombre = 'JORNADA 8 HORAS' JOIN horarios h ON h.nombre = 'AUXILIAR MAÑANA' SET u.horario_id = h.id;
UPDATE usuarios u JOIN horarios j ON j.id = u.horario_id AND j.nombre = 'JORNADA 7 HORAS' JOIN horarios h ON h.nombre = 'AUXILIAR TARDE' SET u.horario_id = h.id;
UPDATE usuarios u JOIN horarios j ON j.id = u.horario_id AND j.nombre = 'JORNADA 9 HORAS' JOIN horarios h ON h.nombre = 'AUXILIAR NOCHE' SET u.horario_id = h.id;
UPDATE horarios SET activo = 0 WHERE nombre IN ('JORNADA 7 HORAS', 'JORNADA 8 HORAS', 'JORNADA 9 HORAS');
UPDATE horarios SET nombre = 'TERAPIA OCUPACIONAL' WHERE nombre = 'REFUERZO 6 HORAS';

-- Ratios exactos que faltaban: tarde 3; limpieza 1 por turno.
INSERT INTO necesidades (nombre, horario_id, puesto_id, dia, personas)
  SELECT 'RATIO AUXILIARES TARDE', id, NULL, 0, 3 FROM horarios WHERE nombre = 'AUXILIAR TARDE' AND activo = 1
  AND NOT EXISTS (SELECT 1 FROM (SELECT horario_id FROM necesidades WHERE activo = 1) n WHERE n.horario_id = horarios.id);
INSERT INTO necesidades (nombre, horario_id, puesto_id, dia, personas)
  SELECT 'RATIO LIMPIEZA MAÑANA', id, NULL, 0, 1 FROM horarios WHERE nombre = 'LIMPIEZA MAÑANA' AND activo = 1
  AND NOT EXISTS (SELECT 1 FROM (SELECT horario_id FROM necesidades WHERE activo = 1) n WHERE n.horario_id = horarios.id);
INSERT INTO necesidades (nombre, horario_id, puesto_id, dia, personas)
  SELECT 'RATIO LIMPIEZA TARDE', id, NULL, 0, 1 FROM horarios WHERE nombre = 'LIMPIEZA TARDE' AND activo = 1
  AND NOT EXISTS (SELECT 1 FROM (SELECT horario_id FROM necesidades WHERE activo = 1) n WHERE n.horario_id = horarios.id);
