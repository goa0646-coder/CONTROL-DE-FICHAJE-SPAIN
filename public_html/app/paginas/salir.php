<?php
if ($u = usuario_actual()) auditar(db(), (int)$u['id'], 'logout');
$_SESSION = [];
session_destroy();
redirigir('login');
