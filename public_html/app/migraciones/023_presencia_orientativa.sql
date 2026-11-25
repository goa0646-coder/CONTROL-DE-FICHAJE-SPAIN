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
