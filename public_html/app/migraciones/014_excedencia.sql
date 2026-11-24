-- Excedencia (no retribuida, días naturales) para poder marcarla entre fechas y recalcular el cuadrante.
INSERT INTO tipos_ausencia (nombre, por_horas, retribuida, dias_anuales, justificante, color, computo)
SELECT 'Excedencia', 0, 0, NULL, 0, '#64748b', 'naturales' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tipos_ausencia WHERE nombre = 'Excedencia');
