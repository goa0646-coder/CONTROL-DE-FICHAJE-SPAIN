# Puesta en marcha en Hostinger (para hacer juntos)

Estado: INDA está listo (`dist/inda.zip`, se genera con `bin/empaquetar.sh`). Falta crear la base de datos y subirlo.

## Qué necesito cuando me conectes
- **Acceso a hPanel** en tu navegador (lo más fácil: conectar tu ordenador a la sesión y yo manejo el navegador; tú pones la contraseña de Hostinger, nunca en el chat).
- O bien **SSH** (hPanel → Avanzado → Acceso SSH): sirve para subir y comprobar, pero **la base de datos solo se puede crear en hPanel**.
- Saber el **dominio o subdominio** que quieres para INDA (p. ej. `inda.tuempresa.com`).
- Un **buzón de correo** del dominio para enviar avisos (p. ej. `no-responder@tuempresa.com`).

## Pasos (unos 15 minutos)
1. hPanel → Dominios → crear el subdominio (si no existe) y activar **SSL**.
2. hPanel → Avanzado → Configuración de PHP → **PHP 8.2 o superior**.
3. hPanel → Bases de datos MySQL → crear la BD y su usuario con contraseña larga. **No se guarda en el repo.**
4. Administrador de archivos → subir `inda.zip` a `public_html` del subdominio y descomprimir.
5. Abrir el subdominio → **asistente**: datos de la BD → crea configuración y tablas → empresa y administrador.
6. hPanel → Cron Jobs → cada 15 minutos: `/usr/bin/php /home/USUARIO/domains/DOMINIO/public_html/app/cron.php`.
7. hPanel → Emails → crear el buzón de envío si no existe.
8. Comprobar: entrar, fichar desde un móvil real (Android e iPhone), instalar la app, activar avisos, revisar Auditoría («integridad comprobada»).
9. Apuntar en `docs/TRASPASO.md` qué quedó instalado (dominio, versión de PHP, triggers sí/no, cron). Sin contraseñas.

## Si algo falla
- Pantalla en blanco o error 500: hPanel → Avanzado → Registros de errores (PHP).
- «No se puede conectar»: el nombre y el usuario llevan el prefijo `u123456789_`; el servidor es `localhost`.
- Aviso de trigger: el plan no permite triggers; INDA sigue protegiendo los fichajes con la cadena de hashes.
