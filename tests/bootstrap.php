<?php
// Las pruebas usan una BD MySQL vacía y desechable: se borran todas sus tablas.
require __DIR__ . '/../vendor/autoload.php';

$GLOBALS['CONFIG'] = ['bd' => [
    'host' => getenv('BD_HOST') ?: '127.0.0.1',
    'nombre' => getenv('BD_NOMBRE') ?: 'fichaje_test',
    'usuario' => getenv('BD_USUARIO') ?: 'test',
    'clave' => getenv('BD_CLAVE') ?: 'test',
]];
require __DIR__ . '/../public_html/app/inicio.php';
require __DIR__ . '/../public_html/app/migrar.php';

$db = db();
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $db->exec("DROP TABLE `$t`");
$db->exec('SET FOREIGN_KEY_CHECKS = 1');
migrar($db);
require __DIR__ . '/Base.php';
