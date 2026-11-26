# Reglas del proyecto INDA

- **Nombre**: el sistema se llama **INDA**. Nada de "Fichaje", "Control horario" ni nombres de terceros (Woffu, etc.) como marca. Diseño, nombres y textos propios.
- **Registro de jornada (art. 34.9 ET)**: inicio y fin diario por persona; hora del servidor (UTC, se muestra en Europe/Madrid); conservación **4 años**; acceso del trabajador, representantes e Inspección.
- **Fichajes inmutables**: la tabla `fichajes` solo admite INSERT (triggers + cadena de hashes). Nunca UPDATE/DELETE. Los errores se arreglan con una `correccion` trazable; el original se sigue viendo.
- **Nada se borra**: bajas = `activo=0`. Excepción: coordenadas GPS a los 12 meses (RGPD).
- **Contraseñas y secretos nunca en el repo**: `config-inda.php` vive fuera de `public_html` y está en `.gitignore`. Ni datos reales de empleados.
- **Esquema**: solo con migraciones nuevas `public_html/app/migraciones/NNN_*.sql`. Nunca editar una ya publicada.
- **Pruebas antes de cada ZIP**: `vendor/bin/phpunit` en verde (MySQL real) antes de `bin/empaquetar.sh`. Cada función nueva lleva su prueba.
- **ZIP limpio para public_html**: `bin/empaquetar.sh` → `dist/inda.zip` (contenido de `public_html` + `config.ejemplo.php`, sin tests, vendor, .git ni docs) y `dist/inda.sql`.
- **Hosting**: Hostinger compartido, PHP 8 + MySQL, sin Node, con cron. Sin frameworks ni dependencias en producción.
- **Respuestas cortas y en español.**

## Forma de trabajar

- Usa `/ponytail` en cada tarea de código nueva.
- Ejecuta `/security-review` antes de cada ZIP y corrige lo grave.
- Al final de cada respuesta, indica en una línea qué skills usaste.
- No revises lo ya hecho salvo que `/security-review` encuentre algo.
- Usa `superpowers:verification-before-completion` antes de decir que algo funciona o de entregar un ZIP.
- Usa `superpowers:systematic-debugging` ante cualquier fallo.
- Usa `superpowers:brainstorming` solo para funciones nuevas grandes.
- Si una tarea es sencilla, avisa de que se puede cambiar a Sonnet (`/model`) para gastar menos; el ahorro real se ve en `/usage`.
