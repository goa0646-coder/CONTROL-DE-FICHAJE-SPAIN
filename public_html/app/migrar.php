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

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__ . '/inicio.php';
    echo implode(PHP_EOL, migrar(db())), PHP_EOL;
}
