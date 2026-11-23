<?php
if ($u = usuario_actual()) auditar(db(), (int)$u['id'], 'logout');
if (($t = $_COOKIE[DISPOSITIVO_COOKIE] ?? '') !== '') consulta(db(), 'UPDATE dispositivos SET activo = 0 WHERE token_hash = ?', [hash('sha256', $t)]);
poner_cookie_dispositivo('');
$_SESSION = [];
session_destroy();
redirigir('login');
