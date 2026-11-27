CREATE TABLE IF NOT EXISTS migraciones (version VARCHAR(100) PRIMARY KEY, aplicada_utc DATETIME NOT NULL) ENGINE=InnoDB;
CREATE TABLE empresa (
  id TINYINT UNSIGNED PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  cif VARCHAR(20) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE centros (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  direccion VARCHAR(200) NOT NULL DEFAULT '',
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  radio_m INT UNSIGNED NOT NULL DEFAULT 150,
  gps_modo ENUM('no','opcional','obligatorio') NOT NULL DEFAULT 'no',
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  num_empleado VARCHAR(20) NULL UNIQUE,
  nombre VARCHAR(80) NOT NULL,
  apellidos VARCHAR(120) NOT NULL DEFAULT '',
  dni VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash VARCHAR(255) NULL,
  rol ENUM('empleado','responsable','admin','inspeccion') NOT NULL DEFAULT 'empleado',
  centro_id INT UNSIGNED NULL,
  responsable_id INT UNSIGNED NULL,
  gps_modo ENUM('no','opcional','obligatorio') NULL,
  aviso_gps_utc DATETIME NULL,
  acceso_hasta DATE NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  alta_utc DATETIME NOT NULL,
  baja_utc DATETIME NULL,
  reset_hash CHAR(64) NULL,
  reset_expira_utc DATETIME NULL,
  FOREIGN KEY (centro_id) REFERENCES centros(id),
  FOREIGN KEY (responsable_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quioscos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  centro_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  alta_utc DATETIME NOT NULL,
  FOREIGN KEY (centro_id) REFERENCES centros(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE correcciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  accion ENUM('anadir','anular','cambiar') NOT NULL,
  fichaje_id BIGINT UNSIGNED NULL,
  tipo_propuesto ENUM('entrada','pausa_inicio','pausa_fin','salida') NULL,
  momento_propuesto_utc DATETIME NULL,
  motivo VARCHAR(500) NOT NULL,
  estado ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
  pedida_por INT UNSIGNED NOT NULL,
  pedida_utc DATETIME NOT NULL,
  resuelta_por INT UNSIGNED NULL,
  resuelta_utc DATETIME NULL,
  comentario VARCHAR(500) NOT NULL DEFAULT '',
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (pedida_por) REFERENCES usuarios(id),
  FOREIGN KEY (resuelta_por) REFERENCES usuarios(id),
  INDEX (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fichajes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  tipo ENUM('entrada','pausa_inicio','pausa_fin','salida') NOT NULL,
  momento_utc DATETIME NOT NULL,
  origen ENUM('movil','web','quiosco','correccion') NOT NULL,
  centro_id INT UNSIGNED NULL,
  quiosco_id INT UNSIGNED NULL,
  zona ENUM('dentro','fuera') NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  correccion_id INT UNSIGNED NULL,
  creado_utc DATETIME NOT NULL,
  hash_anterior CHAR(64) NULL,
  hash CHAR(64) NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (centro_id) REFERENCES centros(id),
  FOREIGN KEY (quiosco_id) REFERENCES quioscos(id),
  FOREIGN KEY (correccion_id) REFERENCES correcciones(id),
  INDEX (usuario_id, momento_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE correcciones ADD FOREIGN KEY (fichaje_id) REFERENCES fichajes(id);

-- Coordenadas aparte: se borran a los 12 meses sin tocar el fichaje (que es inmutable).
CREATE TABLE fichajes_gps (
  fichaje_id BIGINT UNSIGNED PRIMARY KEY,
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  precision_m INT UNSIGNED NULL,
  FOREIGN KEY (fichaje_id) REFERENCES fichajes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auditoria (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  accion VARCHAR(50) NOT NULL,
  detalle VARCHAR(1000) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  momento_utc DATETIME NOT NULL,
  INDEX (momento_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE intentos_login (
  clave VARCHAR(190) NOT NULL,
  momento_utc DATETIME NOT NULL,
  INDEX (clave, momento_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('001_inicial', UTC_TIMESTAMP());
CREATE TRIGGER fichajes_no_update BEFORE UPDATE ON fichajes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los fichajes no se pueden modificar';
CREATE TRIGGER fichajes_no_delete BEFORE DELETE ON fichajes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los fichajes no se pueden borrar';
CREATE TRIGGER auditoria_no_update BEFORE UPDATE ON auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoria no se puede modificar';
CREATE TRIGGER auditoria_no_delete BEFORE DELETE ON auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoria no se puede borrar';

INSERT INTO migraciones VALUES ('002_inmutable', UTC_TIMESTAMP());
-- Fase 2: motivos de pausa, departamentos, horarios, festivos, saldo de horas, avisos y límites de fichaje.
CREATE TABLE motivos_pausa (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  retribuida TINYINT(1) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO motivos_pausa (nombre, retribuida) VALUES ('Comida', 0), ('Descanso', 0), ('Médico', 1), ('Asunto personal', 0);

ALTER TABLE fichajes ADD motivo_id INT UNSIGNED NULL AFTER origen, ADD FOREIGN KEY (motivo_id) REFERENCES motivos_pausa(id);

CREATE TABLE departamentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE horarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  flexible TINYINT(1) NOT NULL DEFAULT 0,
  margen_min SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- dia: 1 = lunes … 7 = domingo. Sin fila = día libre.
CREATE TABLE horario_dias (
  horario_id INT UNSIGNED NOT NULL,
  dia TINYINT UNSIGNED NOT NULL,
  entrada TIME NULL,
  salida TIME NULL,
  minutos SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (horario_id, dia),
  FOREIGN KEY (horario_id) REFERENCES horarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE usuarios ADD departamento_id INT UNSIGNED NULL, ADD horario_id INT UNSIGNED NULL,
  ADD FOREIGN KEY (departamento_id) REFERENCES departamentos(id), ADD FOREIGN KEY (horario_id) REFERENCES horarios(id);

-- Horario de un día concreto (cambios planificados y turnos). horario_id NULL = libre.
CREATE TABLE planificacion (
  usuario_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  horario_id INT UNSIGNED NULL,
  PRIMARY KEY (usuario_id, fecha),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (horario_id) REFERENCES horarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- centro_id NULL = festivo para toda la empresa.
CREATE TABLE festivos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  centro_id INT UNSIGNED NULL,
  fecha DATE NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  FOREIGN KEY (centro_id) REFERENCES centros(id),
  INDEX (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE centros ADD fuera_zona ENUM('aceptar','rechazar') NOT NULL DEFAULT 'aceptar', ADD ips VARCHAR(500) NOT NULL DEFAULT '';

ALTER TABLE empresa ADD horas_anuales SMALLINT UNSIGNED NULL;

-- Movimientos manuales de la bolsa de horas (horas extra pagadas, compensaciones…). Solo se añaden.
CREATE TABLE ajustes_saldo (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  minutos INT NOT NULL,
  motivo VARCHAR(200) NOT NULL,
  creado_por INT UNSIGNED NOT NULL,
  creado_utc DATETIME NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- clave evita avisar dos veces de lo mismo.
CREATE TABLE avisos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  clave VARCHAR(100) NOT NULL,
  texto VARCHAR(500) NOT NULL,
  enlace VARCHAR(200) NOT NULL DEFAULT '',
  creado_utc DATETIME NOT NULL,
  leido_utc DATETIME NULL,
  UNIQUE (usuario_id, clave),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('003_dia_a_dia', UTC_TIMESTAMP());
-- Fase 3: ausencias y vacaciones.
CREATE TABLE tipos_ausencia (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  por_horas TINYINT(1) NOT NULL DEFAULT 0,
  retribuida TINYINT(1) NOT NULL DEFAULT 1,
  dias_anuales DECIMAL(5,1) NULL,
  justificante TINYINT(1) NOT NULL DEFAULT 0,
  color CHAR(7) NOT NULL DEFAULT '#2563eb',
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO tipos_ausencia (nombre, por_horas, retribuida, dias_anuales, justificante, color) VALUES
  ('Vacaciones', 0, 1, 22, 0, '#16a34a'),
  ('Asuntos propios', 0, 1, 2, 0, '#9333ea'),
  ('Visita médica', 1, 1, NULL, 1, '#0891b2'),
  ('Baja por IT', 0, 1, NULL, 1, '#dc2626'),
  ('Permiso retribuido', 0, 1, NULL, 1, '#ea580c'),
  ('Permiso no retribuido', 0, 0, NULL, 0, '#6b7280');

CREATE TABLE ausencias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  tipo_id INT UNSIGNED NOT NULL,
  desde DATE NOT NULL,
  hasta DATE NOT NULL,
  hora_desde TIME NULL,
  hora_hasta TIME NULL,
  motivo VARCHAR(500) NOT NULL DEFAULT '',
  justificante VARCHAR(100) NULL,
  estado ENUM('pendiente','aprobada','rechazada','cancelada') NOT NULL DEFAULT 'pendiente',
  pedida_por INT UNSIGNED NOT NULL,
  pedida_utc DATETIME NOT NULL,
  resuelta_por INT UNSIGNED NULL,
  resuelta_utc DATETIME NULL,
  comentario VARCHAR(500) NOT NULL DEFAULT '',
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (tipo_id) REFERENCES tipos_ausencia(id),
  FOREIGN KEY (pedida_por) REFERENCES usuarios(id),
  FOREIGN KEY (resuelta_por) REFERENCES usuarios(id),
  INDEX (usuario_id, desde),
  INDEX (desde, hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Días asignados a una persona en un año (sustituye a dias_anuales del tipo; incluye lo arrastrado).
CREATE TABLE saldos_ausencia (
  usuario_id INT UNSIGNED NOT NULL,
  tipo_id INT UNSIGNED NOT NULL,
  anio SMALLINT UNSIGNED NOT NULL,
  dias DECIMAL(5,1) NOT NULL,
  PRIMARY KEY (usuario_id, tipo_id, anio),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (tipo_id) REFERENCES tipos_ausencia(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('004_ausencias', UTC_TIMESTAMP());
-- Fase 4: resumen mensual firmado por la persona. Solo se añaden filas.
CREATE TABLE firmas_mensuales (
  usuario_id INT UNSIGNED NOT NULL,
  mes CHAR(7) NOT NULL,
  hash CHAR(64) NOT NULL,
  firmado_utc DATETIME NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  PRIMARY KEY (usuario_id, mes, hash),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TRIGGER firmas_no_update BEFORE UPDATE ON firmas_mensuales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las firmas no se pueden modificar';
CREATE TRIGGER firmas_no_delete BEFORE DELETE ON firmas_mensuales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las firmas no se pueden borrar';

INSERT INTO migraciones VALUES ('005_informes', UTC_TIMESTAMP());
-- Fase 6: documentos, comunicados y avisos push.
CREATE TABLE documentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  titulo VARCHAR(150) NOT NULL,
  archivo VARCHAR(100) NOT NULL,
  nombre_original VARCHAR(200) NOT NULL,
  requiere_firma TINYINT(1) NOT NULL DEFAULT 0,
  subido_por INT UNSIGNED NOT NULL,
  subido_utc DATETIME NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (subido_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- usuario_id NULL en documentos = para toda la plantilla. Firma = confirmación con contraseña + hash del archivo.
CREATE TABLE documentos_firmas (
  documento_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  hash CHAR(64) NOT NULL,
  firmado_utc DATETIME NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  PRIMARY KEY (documento_id, usuario_id),
  FOREIGN KEY (documento_id) REFERENCES documentos(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comunicados (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(150) NOT NULL,
  texto TEXT NOT NULL,
  centro_id INT UNSIGNED NULL,
  autor_id INT UNSIGNED NOT NULL,
  creado_utc DATETIME NOT NULL,
  FOREIGN KEY (centro_id) REFERENCES centros(id),
  FOREIGN KEY (autor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comunicados_leidos (
  comunicado_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  leido_utc DATETIME NOT NULL,
  PRIMARY KEY (comunicado_id, usuario_id),
  FOREIGN KEY (comunicado_id) REFERENCES comunicados(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE suscripciones_push (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  endpoint VARCHAR(700) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  creado_utc DATETIME NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valores internos generados por INDA (p. ej. claves VAPID para push).
CREATE TABLE ajustes (
  clave VARCHAR(50) PRIMARY KEY,
  valor TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('006_portal', UTC_TIMESTAMP());
-- Fase 7: QR, API/extensión/chat, permisos por rol.
ALTER TABLE fichajes MODIFY origen ENUM('movil','web','quiosco','correccion','qr','api','chat') NOT NULL;

CREATE TABLE tokens_api (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  alcance ENUM('personal','lectura') NOT NULL DEFAULT 'personal',
  chat_id VARCHAR(120) NULL UNIQUE,
  creado_utc DATETIME NOT NULL,
  usado_utc DATETIME NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permisos extra que el admin da a cada rol (el admin los tiene todos).
CREATE TABLE permisos_rol (
  rol ENUM('empleado','responsable','inspeccion') NOT NULL,
  permiso VARCHAR(40) NOT NULL,
  PRIMARY KEY (rol, permiso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permisos_rol VALUES ('responsable', 'informes'), ('responsable', 'planificar'), ('inspeccion', 'informes');

INSERT INTO migraciones VALUES ('007_extras', UTC_TIMESTAMP());
-- Sesión que se mantiene en el móvil (como una app). El token solo se guarda como hash.
CREATE TABLE dispositivos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  nombre VARCHAR(150) NOT NULL DEFAULT '',
  creado_utc DATETIME NOT NULL,
  usado_utc DATETIME NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('008_dispositivos', UTC_TIMESTAMP());
-- Reglas de convenio, puestos y necesidades por turno, horas extra planificadas, ofertas de turno y códigos de un solo uso.
ALTER TABLE tipos_ausencia ADD computo ENUM('laborables','naturales') NOT NULL DEFAULT 'laborables';

CREATE TABLE puestos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Qué puestos puede cubrir cada persona (polivalencia).
CREATE TABLE usuarios_puestos (
  usuario_id INT UNSIGNED NOT NULL,
  puesto_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (usuario_id, puesto_id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (puesto_id) REFERENCES puestos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cuántas personas hacen falta en un turno (horario) y puesto. dia 0 = todos los días, 1–7 = lunes–domingo.
CREATE TABLE necesidades (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  centro_id INT UNSIGNED NULL,
  horario_id INT UNSIGNED NOT NULL,
  puesto_id INT UNSIGNED NULL,
  dia TINYINT UNSIGNED NOT NULL DEFAULT 0,
  personas TINYINT UNSIGNED NOT NULL,
  FOREIGN KEY (centro_id) REFERENCES centros(id),
  FOREIGN KEY (horario_id) REFERENCES horarios(id),
  FOREIGN KEY (puesto_id) REFERENCES puestos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- extra = 1: turno aceptado como horas extra (no suma a las horas teóricas). fuerza_mayor: no cuenta en el tope anual (art. 35.3 ET).
ALTER TABLE planificacion ADD puesto_id INT UNSIGNED NULL, ADD extra TINYINT(1) NOT NULL DEFAULT 0, ADD fuerza_mayor TINYINT(1) NOT NULL DEFAULT 0,
  ADD FOREIGN KEY (puesto_id) REFERENCES puestos(id);

-- Turno ofrecido a una persona (cubrir una baja como horas extra). Se acepta con un código de un solo uso.
CREATE TABLE ofertas_turno (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  horario_id INT UNSIGNED NOT NULL,
  puesto_id INT UNSIGNED NULL,
  motivo VARCHAR(200) NOT NULL DEFAULT '',
  fuerza_mayor TINYINT(1) NOT NULL DEFAULT 0,
  estado ENUM('pendiente','aceptada','rechazada','anulada') NOT NULL DEFAULT 'pendiente',
  creado_por INT UNSIGNED NOT NULL,
  creado_utc DATETIME NOT NULL,
  respondida_utc DATETIME NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  metodo VARCHAR(20) NOT NULL DEFAULT '',
  hash CHAR(64) NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (horario_id) REFERENCES horarios(id),
  FOREIGN KEY (puesto_id) REFERENCES puestos(id),
  FOREIGN KEY (creado_por) REFERENCES usuarios(id),
  INDEX (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE codigos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  proposito VARCHAR(60) NOT NULL,
  hash CHAR(64) NOT NULL,
  expira_utc DATETIME NOT NULL,
  intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  INDEX (usuario_id, proposito)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('009_convenio_cuadrante', UTC_TIMESTAMP());
-- Anuncios con destinatarios elegidos y adjunto, acuse de lectura con IP,
-- envíos del trabajador a la empresa y foto de perfil.
ALTER TABLE usuarios ADD foto VARCHAR(100) NULL;

ALTER TABLE comunicados
  ADD archivo VARCHAR(100) NULL,
  ADD nombre_original VARCHAR(200) NULL,
  ADD por_personas TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE comunicados_leidos ADD ip VARCHAR(45) NOT NULL DEFAULT '';

-- Destinatarios de cada anuncio nuevo (por_personas = 1). Los antiguos siguen yendo por centro.
CREATE TABLE comunicados_para (
  comunicado_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (comunicado_id, usuario_id),
  KEY (usuario_id),
  FOREIGN KEY (comunicado_id) REFERENCES comunicados(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lo que el trabajador manda a la empresa (justificantes, bajas, consultas). Sin acuse para el trabajador.
CREATE TABLE envios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  asunto VARCHAR(150) NOT NULL,
  texto TEXT NOT NULL,
  archivo VARCHAR(100) NULL,
  nombre_original VARCHAR(200) NULL,
  creado_utc DATETIME NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  visto_utc DATETIME NULL,
  visto_por INT UNSIGNED NULL,
  KEY (usuario_id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (visto_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('010_anuncios_envios_fotos', UTC_TIMESTAMP());
-- Reglas de presencia mínima (ratios): nombre, vigencia, festivos y activar/desactivar.
ALTER TABLE necesidades
  ADD nombre VARCHAR(100) NOT NULL DEFAULT '',
  ADD desde DATE NULL,
  ADD hasta DATE NULL,
  ADD festivos TINYINT(1) NOT NULL DEFAULT 1,
  ADD activo TINYINT(1) NOT NULL DEFAULT 1;

-- Más datos de la ficha de cada persona.
ALTER TABLE usuarios
  ADD telefono VARCHAR(30) NULL,
  ADD nss VARCHAR(20) NULL,
  ADD fecha_nacimiento DATE NULL;

-- Varios adjuntos por anuncio.
CREATE TABLE comunicados_adjuntos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comunicado_id INT UNSIGNED NOT NULL,
  archivo VARCHAR(100) NOT NULL,
  nombre_original VARCHAR(200) NOT NULL,
  FOREIGN KEY (comunicado_id) REFERENCES comunicados(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('011_ratios_ficha_adjuntos', UTC_TIMESTAMP());
-- Turnos con letra y color para el calendario (M, T, N, P…) y departamento al que pertenecen.
ALTER TABLE horarios
  ADD abreviatura VARCHAR(3) NOT NULL DEFAULT '',
  ADD color VARCHAR(7) NOT NULL DEFAULT '',
  ADD departamento_id INT UNSIGNED NULL,
  ADD FOREIGN KEY (departamento_id) REFERENCES departamentos(id);

INSERT INTO migraciones VALUES ('012_turnos_letras', UTC_TIMESTAMP());
-- Salida registrada por INDA cuando alguien no ficha la salida (se ve como «automática» y se puede corregir).
ALTER TABLE fichajes MODIFY origen ENUM('movil','web','quiosco','correccion','qr','api','chat','automatico') NOT NULL;

INSERT INTO migraciones VALUES ('013_cierre_automatico', UTC_TIMESTAMP());
-- Excedencia (no retribuida, días naturales) para poder marcarla entre fechas y recalcular el cuadrante.
INSERT INTO tipos_ausencia (nombre, por_horas, retribuida, dias_anuales, justificante, color, computo)
SELECT 'Excedencia', 0, 0, NULL, 0, '#64748b', 'naturales' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tipos_ausencia WHERE nombre = 'Excedencia');

INSERT INTO migraciones VALUES ('014_excedencia', UTC_TIMESTAMP());
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

INSERT INTO migraciones VALUES ('015_justificantes_pausa', UTC_TIMESTAMP());
-- Cambios de turno entre compañeros: lo pide uno, acepta el otro y lo aprueba administración o su responsable.
CREATE TABLE cambios_turno (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitante_id INT UNSIGNED NOT NULL,
  companero_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  fecha_devuelve DATE NULL,
  motivo VARCHAR(500) NOT NULL DEFAULT '',
  estado ENUM('companero','aprobacion','aprobado','rechazado','cancelado') NOT NULL DEFAULT 'companero',
  pedido_utc DATETIME NOT NULL,
  respondido_utc DATETIME NULL,
  resuelto_por INT UNSIGNED NULL,
  resuelto_utc DATETIME NULL,
  comentario VARCHAR(500) NOT NULL DEFAULT '',
  FOREIGN KEY (solicitante_id) REFERENCES usuarios(id),
  FOREIGN KEY (companero_id) REFERENCES usuarios(id),
  FOREIGN KEY (resuelto_por) REFERENCES usuarios(id),
  INDEX (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('016_cambios_turno', UTC_TIMESTAMP());
-- Encuestas a la plantilla (anónimas o no) y evaluaciones del desempeño (el responsable valora a cada persona).
CREATE TABLE encuestas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(150) NOT NULL,
  descripcion TEXT NOT NULL,
  tipo ENUM('encuesta','evaluacion') NOT NULL DEFAULT 'encuesta',
  anonima TINYINT(1) NOT NULL DEFAULT 0,
  autor_id INT UNSIGNED NOT NULL,
  creada_utc DATETIME NOT NULL,
  cierra DATE NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (autor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE encuesta_preguntas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  encuesta_id INT UNSIGNED NOT NULL,
  orden TINYINT UNSIGNED NOT NULL,
  texto VARCHAR(300) NOT NULL,
  tipo ENUM('escala','si_no','opciones','texto') NOT NULL,
  opciones TEXT NOT NULL,
  FOREIGN KEY (encuesta_id) REFERENCES encuestas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Quién tiene que responder y, en las evaluaciones, sobre quién.
CREATE TABLE encuesta_asignaciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  encuesta_id INT UNSIGNED NOT NULL,
  responde_id INT UNSIGNED NOT NULL,
  sobre_id INT UNSIGNED NULL,
  respondida_utc DATETIME NULL,
  FOREIGN KEY (encuesta_id) REFERENCES encuestas(id),
  FOREIGN KEY (responde_id) REFERENCES usuarios(id),
  FOREIGN KEY (sobre_id) REFERENCES usuarios(id),
  UNIQUE (encuesta_id, responde_id, sobre_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Cada respuesta enviada. En las anónimas no se guarda la asignación: no se puede saber quién respondió qué.
CREATE TABLE encuesta_envios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  encuesta_id INT UNSIGNED NOT NULL,
  asignacion_id INT UNSIGNED NULL,
  sobre_id INT UNSIGNED NULL,
  FOREIGN KEY (encuesta_id) REFERENCES encuestas(id),
  FOREIGN KEY (asignacion_id) REFERENCES encuesta_asignaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE encuesta_respuestas (
  envio_id INT UNSIGNED NOT NULL,
  pregunta_id INT UNSIGNED NOT NULL,
  valor TEXT NOT NULL,
  PRIMARY KEY (envio_id, pregunta_id),
  FOREIGN KEY (envio_id) REFERENCES encuesta_envios(id),
  FOREIGN KEY (pregunta_id) REFERENCES encuesta_preguntas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('017_encuestas', UTC_TIMESTAMP());
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

INSERT INTO migraciones VALUES ('018_codigos_ratios', UTC_TIMESTAMP());
-- Preferencias de cada trabajador: turnos, días de la semana o fechas que prefiere o quiere evitar.
-- Son sugerencias para el generador (nunca una regla): se cumplen si caben con los ratios y el convenio.
CREATE TABLE preferencias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  tipo ENUM('turno','dia','fecha') NOT NULL,
  horario_id INT UNSIGNED NULL,
  dia TINYINT UNSIGNED NULL,
  fecha DATE NULL,
  prefiere TINYINT(1) NOT NULL DEFAULT 1,
  comentario VARCHAR(300) NOT NULL DEFAULT '',
  creada_utc DATETIME NOT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (horario_id) REFERENCES horarios(id),
  INDEX (usuario_id, activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('019_preferencias', UTC_TIMESTAMP());
-- Cuándo entró por última vez y cuándo se le envió el acceso por email (para saber quién no ha entrado nunca).
ALTER TABLE usuarios ADD ultimo_acceso_utc DATETIME NULL, ADD acceso_enviado_utc DATETIME NULL;

INSERT INTO migraciones VALUES ('020_accesos', UTC_TIMESTAMP());
-- Personas que no tienen que fichar: usan INDA para pedir cosas y ver su horario. Si no fichan, su registro es su horario;
-- si fichan, cuenta lo fichado. No reciben avisos de fichaje ni se les cierra la jornada.
ALTER TABLE usuarios ADD sin_fichaje TINYINT(1) NOT NULL DEFAULT 0;
-- Salida olvidada: se cierra a los 30 minutos del fin del turno (con la hora de fin + 1 minuto) si no se había cambiado.
UPDATE ajustes SET valor = '30' WHERE clave = 'cierre_auto_min' AND valor = '5';

INSERT INTO migraciones VALUES ('021_sin_fichaje', UTC_TIMESTAMP());
-- Pausa obligatoria (no retribuida) de un turno, p. ej. las 2 horas de comida del partido. Las horas del turno ya la descuentan.
ALTER TABLE horarios ADD pausa_min SMALLINT UNSIGNED NOT NULL DEFAULT 0;
UPDATE horarios SET pausa_min = 120 WHERE nombre = 'COORDINACIÓN PARTIDO';
UPDATE horario_dias d JOIN horarios h ON h.id = d.horario_id AND h.nombre = 'COORDINACIÓN PARTIDO' AND d.entrada = '09:00:00' AND d.salida = '20:00:00' SET d.minutos = 540;

INSERT INTO migraciones VALUES ('022_pausa_turno', UTC_TIMESTAMP());
-- Presencia orientativa (no obligatoria) por puesto, p. ej. enfermería: solo informa, no cuenta para el cuadrante ni los huecos.
CREATE TABLE presencias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  puesto_id INT UNSIGNED NOT NULL,
  personas TINYINT UNSIGNED NOT NULL DEFAULT 1,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (puesto_id) REFERENCES puestos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO presencias (nombre, puesto_id, personas)
  SELECT 'PRESENCIA ENFERMERÍA', MIN(id), 1 FROM puestos WHERE nombre LIKE 'ENFERM%' HAVING MIN(id) IS NOT NULL;

INSERT INTO migraciones VALUES ('023_presencia_orientativa', UTC_TIMESTAMP());
-- Pausa automática (no la tiene que fichar; computa como trabajo), fichaje automático según su turno y valoración
-- interna de 1 a 5 estrellas (solo la ve el administrador que la activa).
ALTER TABLE usuarios ADD pausa_auto_min SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD fichaje_auto TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE valoraciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  estrellas TINYINT UNSIGNED NOT NULL,
  nota VARCHAR(500) NOT NULL DEFAULT '',
  autor_id INT UNSIGNED NOT NULL,
  creado_utc DATETIME NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (autor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('024_pausa_auto_valoraciones', UTC_TIMESTAMP());
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

INSERT INTO migraciones VALUES ('025_evaluacion_apuntes', UTC_TIMESTAMP());
-- Cifrado opcional de DNI, nº de la Seguridad Social y fecha de nacimiento (con huella para buscar por DNI) y
-- verificación en dos pasos (código de una app de autenticación) para administración.
ALTER TABLE usuarios MODIFY dni VARCHAR(255) NOT NULL DEFAULT '', MODIFY nss VARCHAR(255) NULL,
  ADD nacimiento_c VARCHAR(255) NULL, ADD dni_huella CHAR(64) NULL, ADD INDEX (dni_huella), ADD totp_secreto VARCHAR(64) NULL;

INSERT INTO migraciones VALUES ('026_cifrado_dos_pasos', UTC_TIMESTAMP());
-- Contratos de cada persona (historial editable): modalidad, categoría, jornada, inicio, fin y convenio.
-- No se borran: un contrato metido por error se marca como anulado.
CREATE TABLE contratos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(80) NOT NULL DEFAULT '',
  categoria VARCHAR(100) NOT NULL DEFAULT '',
  jornada VARCHAR(60) NOT NULL DEFAULT '',
  inicio DATE NOT NULL,
  fin DATE NULL,
  convenio VARCHAR(200) NOT NULL DEFAULT '',
  notas VARCHAR(500) NOT NULL DEFAULT '',
  anulado TINYINT(1) NOT NULL DEFAULT 0,
  creado_por INT UNSIGNED NULL,
  creado_utc DATETIME NOT NULL,
  cambiado_utc DATETIME NULL,
  INDEX (usuario_id, inicio),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('027_contratos', UTC_TIMESTAMP());
-- Anuncios con firma requerida: DNI, firma manuscrita en pantalla, casilla de aceptación y certificado PDF con las evidencias.
ALTER TABLE comunicados ADD firma_requerida TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE acuses_firma (
  comunicado_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  firmado_utc DATETIME NOT NULL,
  dni VARCHAR(255) NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  agente VARCHAR(300) NOT NULL DEFAULT '',
  sesion CHAR(64) NOT NULL DEFAULT '',
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  precision_m INT UNSIGNED NULL,
  hash_texto CHAR(64) NOT NULL,
  hash_firma CHAR(64) NOT NULL,
  documento_id INT UNSIGNED NOT NULL,
  hash_pdf CHAR(64) NOT NULL,
  PRIMARY KEY (comunicado_id, usuario_id),
  FOREIGN KEY (comunicado_id) REFERENCES comunicados(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (documento_id) REFERENCES documentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('028_acuse_firma', UTC_TIMESTAMP());
-- Anuncios con firma requerida en dos pasos: primero la firma de recepción (sin ver el contenido) y después la de lectura y aceptación.
CREATE TABLE recepciones_firma (
  comunicado_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  recibido_utc DATETIME NOT NULL,
  dni VARCHAR(255) NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  agente VARCHAR(300) NOT NULL DEFAULT '',
  sesion CHAR(64) NOT NULL DEFAULT '',
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  precision_m INT UNSIGNED NULL,
  firma_archivo VARCHAR(100) NOT NULL,
  hash_firma CHAR(64) NOT NULL,
  PRIMARY KEY (comunicado_id, usuario_id),
  FOREIGN KEY (comunicado_id) REFERENCES comunicados(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migraciones VALUES ('029_recepcion_firma', UTC_TIMESTAMP());
-- Cambios de turno que incumplen el convenio y los dos aceptan por su propia voluntad (queda el motivo y la aceptación de cada uno).
ALTER TABLE cambios_turno
  ADD incumple VARCHAR(500) NOT NULL DEFAULT '',
  ADD voluntario_companero TINYINT(1) NOT NULL DEFAULT 0;

INSERT INTO migraciones VALUES ('030_cambio_voluntario', UTC_TIMESTAMP());
