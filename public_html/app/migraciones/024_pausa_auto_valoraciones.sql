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
