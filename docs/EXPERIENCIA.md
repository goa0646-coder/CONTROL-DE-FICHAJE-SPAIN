# Experiencia acumulada (INDA)

Lo aprendido trabajando en INDA. Léelo antes de tocar algo grande.

## Entorno de pruebas local

- MariaDB/MySQL local: si se cae, `mysqld_safe --user=mysql &`, esperar ~8 s y `mysql -uroot -e "SET GLOBAL log_bin_trust_function_creators=1"` (necesario para los triggers de `fichajes`).
- `vendor/bin/phpunit`: `tests/bootstrap.php` borra y recrea la BD de pruebas en cada ejecución (contadores y datos empiezan de cero).
- Servidor: `php -S localhost:8080 -t public_html`. Usa la config de `config-inda.php` (fuera del repo).
- `index.php` aplica las migraciones pendientes solo (`migrar_si_hace_falta`). **No** apliques una migración a mano en la BD de pruebas: luego INDA intenta aplicarla otra vez y falla («Duplicate column»). Si ya lo hiciste: `INSERT INTO migraciones VALUES ('030_nombre', UTC_TIMESTAMP())`.
- Errores: la pantalla solo enseña un código; el detalle está en `inda-archivos/errores.log` (o en Administración → Diagnóstico).
- Navegador automático: Playwright con Chromium. Para firmar en un `<canvas>`, primero `scrollIntoViewIfNeeded()`.
- El servidor SMTP falso `tests/smtp_falso.php` solo entiende sesiones con AUTH LOGIN (sin AUTH confunde `DATA` con la contraseña).

## Trampas de PHP que ya nos mordieron

- `"$base·"` dentro de comillas dobles: PHP lee `$base·` como nombre de variable. Usa `"{$base}·"`.
- Atributos `data-*` repetidos entre módulos JS rompen pantallas ajenas (pasó con `data-firma`; ahora `data-firma-anuncio`). Ponles nombre propio.
- Un formulario POST sin `csrf_campo()` da «El formulario ha caducado».
- Al insertar en `ausencias` hace falta `pedida_por`.
- En pruebas, usa DNIs distintos por prueba (hay índice por huella de DNI).

## Producción (Hostinger)

- Hosting compartido LiteSpeed: respeta `.htaccess` (`app/` cerrada entera; `.sql/.log/.md` denegados en la raíz).
- `config-inda.php` y `clave-datos-inda.key` viven **encima** de `public_html`. Si se pierde la clave, los DNI/NSS cifrados no se pueden leer.
- Correo: SMTP `smtp.hostinger.com:465`, usuario = el buzón (`no-responde@indatime.pro`). El «Email de envío» debe coincidir letra a letra con el buzón. Si falla, INDA ahora enseña el motivo real; los registros de entrega están en hPanel → Email Logs.
- Cron: `app/cron.php` cada 15 min (recordatorios, pausas automáticas, purga GPS). Su última ejecución se ve en Diagnóstico.
- Subida: el ZIP se descomprime sobre `public_html`; las migraciones se aplican solas al abrir la web.

## Decisiones del dueño (no cambiar sin preguntar)

- Funciones ocultas (valoraciones 1–5★, apuntes, IA/OCR) solo para la cuenta del dueño (ajuste `valoraciones_admin`); ni otros administradores las ven.
- Cuenta de Inspección sin contraseña: enlace con fecha de caducidad desde Protocolo Inspección.
- Anuncios con firma: **una sola firma** de acuse de recibo (como un certificado de Correos) + botón opcional «Lo he leído».
- Cambios de turno que incumplen el convenio: permitidos solo si los dos marcan «por mi propia voluntad»; la empresa lo ve antes de aprobar.
- Los trabajadores no descargan registros; ven su calendario hasta N días (30/60/120/365) según Empresa.
- Mantenimiento: Empresa → «Cerrar INDA a la plantilla» (recomendado dejar fichar).
- Se rechazó usar criterios encubiertos (p. ej. «nivel de gallego» para medir bajas) en la valoración.

## Flujo de entrega que funciona

1. Rama → cambios + prueba → `vendor/bin/phpunit` verde → prueba en navegador.
2. Revisión de seguridad del diff.
3. Subir `VERSION` y caché de `sw.js`.
4. PR → squash a `master` → `git pull` → `bin/empaquetar.sh` → `entregas/inda-<VERSION>.zip`.
