# INDA · Normas para cualquier agente (Claude Code, Antigravity, Gemini…)

Este repositorio es **la única fuente de verdad** y el puente entre agentes. Lo que no esté en `master` no existe.
Normas del proyecto: [CLAUDE.md](CLAUDE.md) (valen para todos). Experiencia y trucos: [docs/EXPERIENCIA.md](docs/EXPERIENCIA.md). Estado: [docs/ESTADO.md](docs/ESTADO.md).

## 1. Cómo trabajamos en paralelo

1. **Antes de empezar**: `git pull` de `master`. Nunca trabajes sobre una copia vieja ni sobre el servidor.
2. **Una rama por tarea** (`git checkout -b nombre-corto`), commits pequeños, PR a `master` y fusión (squash).
3. **Nunca editar en el servidor (Hostinger) a mano.** `master` puede subirse solo por FTP (GitHub Actions, si están puestos los secretos FTP) y además el ZIP sobrescribe `public_html`: lo editado allí se pierde. Si ya hay cambios hechos en el servidor, primero se copian a una rama del repo.
4. **Migraciones**: el siguiente número libre en `public_html/app/migraciones/`. Antes de crear una, `git pull` y mira el último número; si dos ramas cogen el mismo, la segunda en fusionarse lo renumera. Nunca editar una ya publicada.
5. **Versión**: cada entrega sube `VERSION` en `public_html/app/inicio.php` (formato `AAAA.MM.DD` + letra) y el nombre de caché en `public_html/sw.js` (`inda-vN`). Si hay conflicto, gana el más alto +1.
6. **Entrega**: pruebas en verde → `bin/empaquetar.sh` → copia `dist/inda.zip` a `entregas/inda-<VERSION>.zip` y apúntalo en `entregas/README.md`.
7. Al terminar una tarea, añade una línea a `docs/ESTADO.md` (qué, versión, PR).

## 2. Reglas técnicas (resumen; el detalle en CLAUDE.md)

- **Sin dependencias en producción**: PHP 8 puro + PDO + MySQL. Sin Composer en `public_html`, sin Node, sin frameworks. (`vendor/` es solo para las pruebas.)
- **Fichajes inmutables**: la tabla `fichajes` solo admite INSERT (triggers + cadena de hashes). Los errores se arreglan con una `correccion`; para leer fichajes válidos usa la constante `NO_ANULADO`.
- **Nada se borra**: bajas = `activo = 0`. Excepción: GPS a los 12 meses (RGPD). Planificación (cuadrantes futuros) sí se puede borrar.
- **Horas**: guardar en UTC (`ahora()`), mostrar en Europe/Madrid (`local()`). Para comparar horas de turno usa `ts_local($fecha, $hora)`, nunca cadenas tipo `'23:00' > '00:30'`.
- **Acceso**: cada página empieza con `$yo = requiere('rol', …)`; permisos finos con `puede($u, 'permiso')`, `puede_ver($yo, $otro)`, `equipo_de()`. Roles por igualdad estricta, nunca `str_contains`.
- **Funciones ocultas del dueño** (valoraciones, IA/OCR): solo con `ve_valoraciones()` / `ia_permitida()`. La cuenta del dueño no la puede tocar nadie (`protegida()`).
- **IA (Gemini)**: a `ia.php` solo llegan datos ya filtrados con `personas_visibles($db, $yo)`. La salida de la IA se escapa con `e()` y nunca va a SQL.
- **Seguridad**: SQL siempre con parámetros (`consulta($db, $sql, [$p])`); en la cadena solo `(int)`. Todo HTML con `e()`. Todo POST lleva `csrf_campo()` (se comprueba en `index.php`). Subidas: lista de extensiones, nombre aleatorio, fuera de `public_html` (`carpeta_archivos()`).
- **Transacciones** para todo lo que escribe en varias tablas a la vez (firmas, correcciones, cuadrantes).
- **Avisos**: `notificar()` (campana + email + push), `avisar_administracion()`, `auditar()` en toda acción con efecto.
- **Secretos y datos reales nunca en el repo**: `config-inda.php`, `clave-datos-inda.key`, Excel/PDF de trabajadores. Ya están en `.gitignore`.
- **Textos**: en español, cortos, sin marcas de terceros (nada de «Woffu», «Fichaje»…). El sistema se llama **INDA**.

## 3. Pruebas

- `vendor/bin/phpunit` contra MySQL real, en verde antes de cada ZIP. Cada función nueva lleva su prueba en `tests/`.
- Cambios de pantalla: probar en navegador (`php -S localhost:8080 -t public_html`) con un usuario de cada rol.

## 4. Forma de trabajar (heredada de Claude Code)

- **Lo mínimo que resuelve del todo** (filosofía *ponytail*: https://github.com/goa0646-coder/ponytail): sin capas ni abstracciones de más; borrar antes que añadir.
- Revisión de seguridad antes de cada ZIP y corregir lo grave.
- Ante un fallo: reproducirlo, encontrar la causa, arreglarla y añadir la prueba (depuración sistemática). No dar nada por bueno sin verlo funcionar.
- Respuestas al dueño: cortas y en español.
