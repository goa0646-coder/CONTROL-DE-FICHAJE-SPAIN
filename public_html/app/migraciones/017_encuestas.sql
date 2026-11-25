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
