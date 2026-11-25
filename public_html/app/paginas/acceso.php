<?php
// Entrada de Inspección con el enlace que genera administración (sin contraseña). Queda en la auditoría.
$u = usuario_de_enlace(db(), (string)($_GET['t'] ?? ''));
if (!$u) {
    aviso('El enlace no es válido o ha caducado. Pide uno nuevo a la empresa.', 'error');
    redirigir('login');
}
session_regenerate_id(true);
$_SESSION['uid'] = $u['id'];
consulta(db(), 'UPDATE usuarios SET ultimo_acceso_utc = ? WHERE id = ?', [ahora(), $u['id']]);
auditar(db(), (int)$u['id'], 'login', 'enlace de Inspección');
redirigir(inicio_de($u));
