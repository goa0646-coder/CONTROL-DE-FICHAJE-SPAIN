<?php
// Copia este archivo como config-fichaje.php en la carpeta que está POR ENCIMA de public_html.
// Nunca lo subas al repositorio.
return [
    'bd' => [
        'host' => 'localhost',
        'nombre' => 'u000000000_fichaje',
        'usuario' => 'u000000000_fichaje',
        'clave' => 'CAMBIAR',
    ],
    'url' => 'https://fichaje.miempresa.com',
    'correo_desde' => 'no-responder@miempresa.com',
    // Mínimo 16 caracteres al azar. Solo para ?p=instalar.
    'clave_instalacion' => 'CAMBIAR-POR-UNA-CLAVE-LARGA',
];
