-- Cuándo entró por última vez y cuándo se le envió el acceso por email (para saber quién no ha entrado nunca).
ALTER TABLE usuarios ADD ultimo_acceso_utc DATETIME NULL, ADD acceso_enviado_utc DATETIME NULL;
