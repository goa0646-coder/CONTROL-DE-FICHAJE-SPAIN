-- Personas que no tienen que fichar: usan INDA para pedir cosas y ver su horario. Si no fichan, su registro es su horario;
-- si fichan, cuenta lo fichado. No reciben avisos de fichaje ni se les cierra la jornada.
ALTER TABLE usuarios ADD sin_fichaje TINYINT(1) NOT NULL DEFAULT 0;
-- Salida olvidada: se cierra a los 30 minutos del fin del turno (con la hora de fin + 1 minuto) si no se había cambiado.
UPDATE ajustes SET valor = '30' WHERE clave = 'cierre_auto_min' AND valor = '5';
