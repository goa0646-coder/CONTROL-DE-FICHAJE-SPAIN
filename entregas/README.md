# Entregas (ZIP para public_html)

Cada ZIP es el contenido de `public_html` + `config.ejemplo.php` (sin pruebas, vendor ni datos). Se sube a Hostinger y se descomprime sobre `public_html`; las migraciones se aplican solas.

| Versión | Qué trae |
|---|---|
| ~~2026.11.27e~~ | 27/11/2026 | **RETIRADA, no subir**: rompe la web en MariaDB (migración 031) y falla en 60 pruebas. Sustituida por 2026.11.27f. |
| 2026.11.26g | Botón de mantenimiento (cerrar INDA a la plantilla) |
| 2026.11.26h | Borrar cuadrante entre fechas |
| 2026.11.26i | Recuperar contraseña por el SMTP |
| 2026.11.26j | Motivo real cuando falla el correo |
| 2026.11.27b | Cambios voluntarios aunque incumplan el convenio, una sola firma en anuncios, aviso sin tipos de ausencia |
| 2026.11.27c | Límite de intentos por conexión y 2FA por cuenta, display_errors off |
| 2026.11.27d | Vista previa de cambio mañana ⇄ tarde/noche |
| **2026.11.27f** | **Última.** Lo mismo que la 27d + los arreglos buenos de la 27e (correo, ausencias, intentos al firmar), corregidos y con las 180 pruebas en verde |

`inda-esquema.sql`: esquema completo de la base de datos (para instalar desde cero).
