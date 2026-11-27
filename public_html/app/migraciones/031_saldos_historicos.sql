CREATE TABLE IF NOT EXISTS saldos_historicos (
    usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    fecha DATE NOT NULL,
    valor INT NOT NULL,
    PRIMARY KEY (usuario_id, fecha)
);
