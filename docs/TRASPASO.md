# Traspaso — INDA

Fecha: 23/11/2026.

## Estado

- **Fase 1 terminada y fusionada en `master`** (PR de `fase-1`): fichar (web/móvil PWA, quiosco con nº + PIN), pausas, GPS por centro (dentro/fuera de zona, coordenadas borradas a los 12 meses), historial con horas y CSV, correcciones con aprobación, equipo en tiempo real, empresa/centros/usuarios/quioscos, rol Inspección con caducidad, auditoría con verificación de la cadena de hashes, recuperar contraseña por email, bloqueo tras 5 fallos.
- Sistema renombrado a **INDA** (títulos, manifest, ZIP `inda.zip`, config `config-inda.php`; `config-fichaje.php` sigue valiendo).
- Pruebas: PHPUnit contra MySQL (`tests/`), también en GitHub Actions (`.github/workflows/pruebas.yml`).
- Análisis de Woffu y plan de fases: [WOFFU.md](WOFFU.md) — **pendiente de aprobación** del dueño antes de programar.

## Hostinger

No consta en el repo ni en el historial qué hay instalado. Si ya se subió una versión:
- Archivos en `public_html` del subdominio; configuración en `config-fichaje.php` (o `config-inda.php`) **por encima** de `public_html`.
- Cron diario de `app/cron.php` (purga GPS).
- Para actualizar: subir el ZIP nuevo, sobrescribir y abrir `?p=instalar&clave=…` (aplica migraciones pendientes).
- **Por confirmar con el dueño**: dominio/subdominio, versión de PHP, si los triggers MySQL se crearon (aviso en la instalación) y si el cron está puesto.

## Pendientes

1. Aprobar el plan de [WOFFU.md](WOFFU.md) (fases 2+).
2. Confirmar los datos de Hostinger de arriba.
3. El dueño renombra el repo a "INDA" y lo pone privado; actualizar el remoto local: `git remote set-url origin https://github.com/goa0646-coder/INDA`.
4. Copia mensual de la BD (phpMyAdmin → Exportar).

## Cómo seguir

`composer install` → `vendor/bin/phpunit` → `bin/empaquetar.sh` → subir `dist/inda.zip`. Reglas en [../CLAUDE.md](../CLAUDE.md).
