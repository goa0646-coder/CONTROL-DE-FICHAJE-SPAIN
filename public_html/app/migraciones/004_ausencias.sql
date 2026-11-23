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
