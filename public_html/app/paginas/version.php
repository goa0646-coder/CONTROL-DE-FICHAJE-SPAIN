<?php
// Versión instalada (sin sesión): la app la consulta para avisar de que hay una nueva.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo VERSION;
