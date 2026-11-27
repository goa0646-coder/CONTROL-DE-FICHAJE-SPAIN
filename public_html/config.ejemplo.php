<?php
// Copia este archivo como config-inda.php en la carpeta que está POR ENCIMA de public_html.
// Nunca lo subas al repositorio.
return [
    'bd' => [
        'host' => 'localhost',
        'nombre' => 'u000000000_fichaje',
        'usuario' => 'u000000000_fichaje',
        'clave' => 'CAMBIAR',
    ],
    'url' => 'https://inda.miempresa.com',
    'correo_desde' => 'no-responder@miempresa.com',
    // Mínimo 16 caracteres al azar. Solo para ?p=instalar.
    'clave_instalacion' => 'CAMBIAR-POR-UNA-CLAVE-LARGA',
    // Opcional: carpeta de justificantes y documentos (por defecto, inda-archivos junto a public_html).
    // 'archivos' => '/home/u000000000/inda-archivos',
    // Opcional: entrar con Google o Microsoft. URL de retorno: https://inda.miempresa.com/?p=sso
    // 'sso' => ['proveedor' => 'google', 'cliente_id' => '', 'secreto' => ''],
    // 'sso' => ['proveedor' => 'microsoft', 'cliente_id' => '', 'secreto' => '', 'tenant' => 'ID-DEL-DIRECTORIO'],
    // Opcional: leer DNI, convenios y calendarios con Google Gemini (aistudio.google.com → API key).
    // Para DNI usa una cuenta con facturación activada: así Google no usa los datos.
    // 'gemini_clave' => '',
    // 'gemini_modelo' => 'gemini-flash-latest',
    // Opcional: fichar desde Slack (/inda) y Microsoft Teams (webhook saliente).
    // 'slack_secreto' => '',
    // 'teams_secreto' => '',
];
