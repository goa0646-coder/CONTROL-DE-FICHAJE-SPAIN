<?php
// Aplica las migraciones pendientes. Uso por SSH: php public_html/app/migrar.php

function migrar(PDO $db): array
{
    $db->exec('CREATE TABLE IF NOT EXISTS migraciones (version VARCHAR(100) PRIMARY KEY, aplicada_utc DATETIME NOT NULL) ENGINE=InnoDB');
    $hechas = $db->query('SELECT version FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
    $log = [];
    foreach (glob(__DIR__ . '/migraciones/*.sql') as $archivo) {
        $version = basename($archivo, '.sql');
        if (in_array($version, $hechas, true)) continue;
        foreach (preg_split('/;\s*$/m', file_get_contents($archivo)) as $sql) {
            if (trim($sql) === '') continue;
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                // Algunos hostings no dejan crear triggers: la cadena de hashes sigue detectando cambios.
                if (!str_starts_with(ltrim($sql), 'CREATE TRIGGER')) throw $e;
                $log[] = "AVISO: no se pudo crear un trigger ({$e->getMessage()})";
            }
        }
        $db->prepare('INSERT INTO migraciones VALUES (?, ?)')->execute([$version, gmdate('Y-m-d H:i:s')]);
        $log[] = "Aplicada $version";
    }
    return $log ?: ['Nada pendiente'];
}

// Al subir una versión nueva por FTP, la primera visita aplica sola las migraciones pendientes
// (con un bloqueo para que dos visitas a la vez no lo hagan dos veces).
function migrar_si_hace_falta(PDO $db): bool
{
    $archivos = count(glob(__DIR__ . '/migraciones/*.sql'));
    try {
        if ((int)$db->query('SELECT COUNT(*) FROM migraciones')->fetchColumn() >= $archivos) return false;
    } catch (PDOException) {
        // Sin tabla de migraciones: base de datos vacía, la prepara el asistente o ?p=instalar.
        return false;
    }
    if (!$db->query("SELECT GET_LOCK('inda_migrar', 30)")->fetchColumn()) return false;
    try {
        migrar($db);
    } finally {
        $db->query("SELECT RELEASE_LOCK('inda_migrar')");
    }
    return true;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__ . '/inicio.php';
    echo implode(PHP_EOL, migrar(db())), PHP_EOL;
}
