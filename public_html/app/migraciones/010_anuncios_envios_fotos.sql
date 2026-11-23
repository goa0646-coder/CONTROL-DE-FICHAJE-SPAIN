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
