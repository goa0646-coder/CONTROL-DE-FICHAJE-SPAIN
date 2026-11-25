-- Cifrado opcional de DNI, nº de la Seguridad Social y fecha de nacimiento (con huella para buscar por DNI) y
-- verificación en dos pasos (código de una app de autenticación) para administración.
ALTER TABLE usuarios MODIFY dni VARCHAR(255) NOT NULL DEFAULT '', MODIFY nss VARCHAR(255) NULL,
  ADD nacimiento_c VARCHAR(255) NULL, ADD dni_huella CHAR(64) NULL, ADD INDEX (dni_huella), ADD totp_secreto VARCHAR(64) NULL;
