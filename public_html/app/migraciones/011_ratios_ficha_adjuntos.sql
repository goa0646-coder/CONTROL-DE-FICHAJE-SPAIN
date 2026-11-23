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
