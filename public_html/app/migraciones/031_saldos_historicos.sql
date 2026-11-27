-- Tabla que dejó un parche anterior para guardar saldos; ya no se usa (el saldo se calcula siempre desde el alta).
-- usuario_id UNSIGNED como usuarios.id y sin clave foránea: así se crea igual en MySQL y en MariaDB.
CREATE TABLE IF NOT EXISTS saldos_historicos (
    usuario_id INT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    valor INT NOT NULL,
    PRIMARY KEY (usuario_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
