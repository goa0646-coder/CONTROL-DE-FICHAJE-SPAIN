# Traspaso — INDA

Fecha: 23/11/2026.

## Estado

- **Fase 1 terminada y fusionada en `master`** (PR de `fase-1`): fichar (web/móvil PWA, quiosco con nº + PIN), pausas, GPS por centro (dentro/fuera de zona, coordenadas borradas a los 12 meses), historial con horas y CSV, correcciones con aprobación, equipo en tiempo real, empresa/centros/usuarios/quioscos, rol Inspección con caducidad, auditoría con verificación de la cadena de hashes, recuperar contraseña por email, bloqueo tras 5 fallos.
- Sistema renombrado a **INDA** (títulos, manifest, ZIP `inda.zip`, config `config-inda.php`; `config-fichaje.php` sigue valiendo).
- Pruebas: PHPUnit contra MySQL (`tests/`), también en GitHub Actions (`.github/workflows/pruebas.yml`).
- Análisis de Woffu y plan: [WOFFU.md](WOFFU.md). Plan aprobado sin teletrabajo.
- **Fases 2–7 fusionadas en `master`** (PR #2) (migraciones 003–007): motivos de pausa, departamentos, horarios, festivos, turnos y cuadrante, horas extra/nocturnas, bolsa de horas, avisos (app, email, push), ausencias y vacaciones con saldos, calendario de equipo, informes, firma mensual, documentos con firma, comunicados, QR, API, extensión, Slack/Teams, entrar con Google/Microsoft, permisos por rol. Detalle en [DISENO.md](DISENO.md) §6.
- Fichar desde el móvil como una app (sesión mantenida por dispositivo, botón Instalar) — migración 008.
- Asistente de instalación: sin `config-inda.php`, la web pide los datos de la BD y crea la configuración y las tablas.
- No hecho a propósito: teletrabajo, WhatsApp, biométricos, idiomas (ver DISENO §8).

## Hostinger

No consta en el repo ni en el historial qué hay instalado. Si ya se subió una versión:
- Archivos en `public_html` del subdominio; configuración en `config-fichaje.php` (o `config-inda.php`) **por encima** de `public_html`.
- Cron diario de `app/cron.php` (purga GPS).
- Para actualizar: subir el ZIP nuevo, sobrescribir y abrir `?p=instalar&clave=…` (aplica migraciones pendientes).
- **Por confirmar con el dueño**: dominio/subdominio, versión de PHP, si los triggers MySQL se crearon (aviso en la instalación) y si el cron está puesto.

## Pendientes

1. **Instalar en Hostinger** con el dueño: crear la BD en hPanel y usar el asistente. Guía: [HOSTINGER.md](HOSTINGER.md).
2. Cambiar el cron a **cada 15 minutos** (INSTALAR §5) y revisar que `correo_desde` es un buzón real.
3. Configurar horarios, festivos y tipos de ausencia (INSTALAR §6).
4. Confirmar los datos de Hostinger de arriba.
5. El dueño renombra el repo a "INDA" y lo pone privado; actualizar el remoto local: `git remote set-url origin https://github.com/goa0646-coder/INDA`.
6. Copia mensual de la BD (phpMyAdmin → Exportar) y de la carpeta `inda-archivos`.

## Cómo seguir

`composer install` → `vendor/bin/phpunit` → `bin/empaquetar.sh` → subir `dist/inda.zip`. Reglas en [../CLAUDE.md](../CLAUDE.md).
