# Diseño — INDA (control horario)

Estado: **aprobado**. Fases 1–7 implementadas.

## 1. Contexto y límites

- Una empresa, 20–100 empleados, varios centros.
- Hostinger compartido: PHP 8 + MySQL, sin Node, con cron. HTTPS obligatorio.
- Repo público: ni contraseñas ni datos de empleados. `config.php` vive **fuera** de `public_html`.
- Sin frameworks. Sin dependencias en producción (Composer solo para PHPUnit en desarrollo).

## 2. Reglas legales que el sistema garantiza

| Regla (art. 34.9 ET + borrador de RD de registro digital) | Cómo se cumple |
|---|---|
| Registro diario de inicio y fin por persona | Tabla `fichajes`: entrada, salida, inicio/fin de pausa. |
| Hora fiable y objetiva | La hora la pone **el servidor** (UTC). Nunca el móvil. Se muestra en hora de Madrid. |
| Inalterable | `fichajes` solo admite INSERT. Triggers MySQL bloquean UPDATE/DELETE. Cadena de hashes (cada fichaje guarda el hash del anterior) para detectar manipulación directa en la BD. |
| Correcciones trazables | Nunca se toca el original. Se crea una `correccion` (motivo, quién pide, quién aprueba, cuándo). Al aprobar se añade un fichaje nuevo con origen `correccion` y/o se marca el original como anulado en la propia corrección. Se ven ambos. |
| Conservación 4 años | Nada se borra. Las bajas se desactivan (`activo=0`), no se eliminan. |
| Acceso del trabajador | Cada empleado ve y descarga su registro (CSV fase 1; PDF/Excel fase 4). |
| Acceso Inspección / representantes | Rol `inspeccion`: solo lectura de todo, con fecha de caducidad. Cada consulta queda en `auditoria`. |
| GPS proporcional (RGPD) | Solo en el instante de fichar, nunca seguimiento. Configurable por centro y por persona. Aviso informativo antes del primer uso. |

## 3. Roles y permisos

| Acción | Empleado | Responsable | Admin | Inspección |
|---|---|---|---|---|
| Fichar (móvil/PC/quiosco) | ✔ | ✔ | ✔ | — |
| Ver/descargar su registro | ✔ | ✔ | ✔ | — |
| Pedir corrección propia | ✔ | ✔ | ✔ | — |
| Ver registro de su equipo | — | ✔ (sus empleados) | ✔ (todos) | ✔ (todos, solo lectura) |
| Aprobar/rechazar correcciones | — | ✔ (su equipo, nunca las suyas) | ✔ (nunca las suyas) | — |
| Empresa, centros, empleados, quioscos, GPS | — | — | ✔ | — |
| Ver auditoría | — | — | ✔ | ✔ |

"Su equipo" = empleados cuyo `responsable_id` es él.

## 4. Pantallas (fase 1)

Todas responsive; la web es PWA instalable (manifest + service worker solo para caché de la interfaz).

**Común**
1. Login (email + contraseña). Bloqueo temporal tras 5 fallos.
2. Cambiar contraseña / recuperar por email.

**Empleado**
3. **Fichar**: reloj, estado actual (fuera / trabajando / en pausa) y solo los botones válidos (Entrar, Pausa, Volver, Salir). Pide GPS si toca.
4. **Mi historial**: lista por días con horas trabajadas, filtro por mes, botón Descargar CSV.
5. **Mis correcciones**: nueva solicitud (añadir fichaje olvidado / anular uno / cambiar hora) + estado de las enviadas.

**Responsable**
6. **Equipo hoy**: quién está dentro, en pausa o fuera.
7. **Correcciones pendientes**: aprobar / rechazar con comentario.
8. **Registro de un empleado**: igual que "Mi historial" pero de otro.

**Admin**
9. Empresa (nombre, CIF).
10. Centros (dirección, coordenadas, radio, modo GPS).
11. Empleados (alta, baja, rol, centro, responsable, PIN de quiosco, excepción GPS).
12. Quioscos (dar de alta una tablet, revocarla).
13. Usuarios de Inspección (alta con caducidad).
14. Auditoría.

**Quiosco** (tablet en la entrada)
15. Pantalla fija: el empleado teclea su nº de empleado + PIN → ve su nombre y estado → pulsa el botón → vuelve a la pantalla inicial a los 5 s. La tablet se activa una vez con un código del admin (cookie de larga duración). Sin GPS: el centro es el del quiosco.

**Inspección**
16. Buscador de empleado/periodo → registro en solo lectura con correcciones visibles + descarga.

## 5. Tablas (fase 1)

Fechas en `DATETIME` UTC. Todas `InnoDB`, `utf8mb4`.

- **empresa**: id, nombre, cif.
- **centros**: id, nombre, direccion, lat, lng, radio_m, gps_modo (`no` / `opcional` / `obligatorio`), activo.
- **usuarios**: id, num_empleado, nombre, apellidos, dni, email, password_hash, pin_hash, rol (`empleado` / `responsable` / `admin` / `inspeccion`), centro_id, responsable_id, gps_modo (NULL = el del centro), aviso_gps_visto_utc, acceso_hasta (inspección), activo, alta_utc, baja_utc.
- **fichajes** *(solo INSERT)*: id, usuario_id, tipo (`entrada` / `salida` / `pausa_inicio` / `pausa_fin`), momento_utc, origen (`movil` / `web` / `quiosco` / `correccion`), centro_id, quiosco_id, zona, ip, correccion_id, creado_utc, hash_anterior, hash.
- **fichajes_gps**: fichaje_id, lat, lng, precision_m. Se borra a los 12 meses.
- **correcciones**: id, usuario_id, accion (`anadir` / `anular` / `cambiar`), fichaje_id (el original, si aplica), tipo_propuesto, momento_propuesto_utc, motivo, estado (`pendiente` / `aprobada` / `rechazada`), pedida_por, pedida_utc, resuelta_por, resuelta_utc, comentario.
- **quioscos**: id, centro_id, nombre, token_hash, activo, alta_utc.
- **auditoria**: id, usuario_id, accion, detalle (JSON), ip, momento_utc. Solo INSERT.
- **intentos_login**: clave (email o IP), momento_utc.
- **migraciones**: version, aplicada_utc.

Reglas de cálculo:
- Los fichajes se emparejan **por secuencia**, no por fecha (vale para turnos que cruzan la medianoche).
- Secuencia válida: fuera → entrada → (pausa_inicio → pausa_fin)* → salida. El servidor rechaza lo demás.
- Fichaje anulado = existe una corrección aprobada que lo anula. Se sigue mostrando, tachado.
- Jornada sin salida = "abierta"; el empleado debe pedir corrección.

## 6. Fases 2–7 (implementadas)

**Cálculo de horas** (`app/horarios.php`): cada día tiene un horario teórico. Prioridad: `planificacion` (cambio o turno de ese día; NULL = libre) > `festivos` (empresa o centro) > `usuarios.horario_id`. Las ausencias aprobadas ponen el teórico a 0 (por días) o restan sus minutos (por horas). Las horas trabajadas se asignan al día de la entrada. Extra = trabajado − teórico (si es positivo). Nocturnas = 22:00–6:00 hora de Madrid. Bolsa de horas = suma de diferencias de días ya pasados desde el alta + `ajustes_saldo`. Las pausas con motivo retribuido cuentan como trabajo.

- **Fase 2 – Día a día** (`003`): `motivos_pausa` (+ `fichajes.motivo_id`, incluido en el hash solo si existe), `departamentos`, `horarios` + `horario_dias`, `planificacion`, `festivos`, `ajustes_saldo`, `avisos`; centros con `fuera_zona` (aceptar/rechazar) e `ips` permitidas; `empresa.horas_anuales`. Cron cada 15 min: falta la entrada (a la persona), retraso (al responsable), jornada sin cerrar.
- **Fase 3 – Ausencias** (`004`): `tipos_ausencia` (por días u horas, retribuida, días/año, pide justificante, color), `ausencias` (pendiente/aprobada/rechazada/cancelada), `saldos_ausencia` (asignado por persona y año, con arrastre). Días = laborables según el horario. Calendario de equipo; al aprobar se ven los solapes. Los compañeros ven «ausente», no el tipo (puede ser dato de salud).
- **Fase 4 – Informes y firma** (`005`): el cron avisa el día 1 (y otra vez el 5, desde las 9:00) a quien no ha firmado el mes anterior; Informes muestra, para un mes completo, fecha e IP de cada firma; Documentos muestra quién ha firmado, cuándo y desde qué IP. Festivos importables desde .ics o CSV.
  `firmas_mensuales` (solo INSERT, con triggers): la persona firma con su contraseña el hash del contenido del mes; si después hay una corrección, la firma deja de valer y se puede firmar de nuevo. Informes por periodo, departamento y centro; CSV para Excel/gestoría; PDF = imprimir desde el navegador.
- **Fase 5 – Turnos**: los turnos son horarios. Cuadrante semanal editable y asignación por fechas con secuencia rotativa (opcional escalonada). Cada cambio avisa a la persona.
- **Fase 6 – Portal** (`006`): `documentos` + `documentos_firmas` (hash del archivo + contraseña), `comunicados` + `comunicados_leidos`, `suscripciones_push` y `ajustes` (claves VAPID). Push sin contenido: el service worker pide a INDA el último aviso.
- **Fase 7 – Extras** (`007`): origen `qr`/`api`/`chat`; `tokens_api` (personal para extensión y chat; lectura para gestoría); `permisos_rol` (informes, planificar, documentos, comunicados). QR del quiosco cambia cada 30 s (HMAC del token del quiosco); cartel fijo por centro. Slack (firma v0) y Teams (HMAC). Entrar con Google o Microsoft (OIDC; Microsoft exige el ID del directorio).

**Fichar desde el móvil como una app** (`008`): PWA instalable; al entrar con «mantener la sesión» se guarda en `dispositivos` el hash de un token y el móvil recibe una cookie `inda_dispositivo` (HttpOnly, 1 año, se renueva con el uso). Se cierra al salir, desde Perfil, al cambiar la contraseña o al dar de baja. Inspección no puede mantener sesión. Activar un quiosco borra la sesión guardada del admin en esa tablet.

Archivos (justificantes, documentos): fuera de `public_html` (`inda-archivos`), nombre aleatorio, se sirven por `?p=archivo` comprobando permiso.

## 7. Técnica

```
public_html/            → se sube tal cual (ZIP)
  index.php             → router mínimo
  assets/               → css, js, manifest, service-worker
  app/                  → código PHP (bloqueado con .htaccess)
    controladores/  modelos/  vistas/  lib/
  migraciones/          → 001_inicial.sql, 002_..., (bloqueado con .htaccess)
../config.php           → fuera de public_html: BD, clave secreta, SMTP
tests/                  → PHPUnit (no va en el ZIP)
```

- Acceso a BD con PDO y consultas preparadas. CSRF en todos los formularios. Cookies `Secure`, `HttpOnly`, `SameSite=Lax`. `password_hash` para contraseñas y PIN.
- Migraciones: archivos SQL numerados; `php bin/migrar.php` (o página de admin protegida si no hay SSH) aplica las pendientes y las anota en `migraciones`.
- Pruebas: PHPUnit contra MySQL real (local y GitHub Actions). Mínimo: secuencia de fichajes, inmutabilidad, correcciones, permisos por rol, cálculo de horas.
- Entrega: ZIP + `INSTALAR.md` (crear BD, subir, poner `config.php`, ejecutar migraciones, crear admin).

## 8. Fuera de alcance (a propósito)

- Teletrabajo marcado (descartado por el dueño).
- Fichar sin conexión (la hora la pone el servidor).
- Foto o reconocimiento facial en quiosco, y terminales biométricos (requieren hardware y datos biométricos: RGPD art. 9).
- WhatsApp (requiere cuenta de pago de Meta Business).
- Varios idiomas (la plantilla trabaja en español).
- Varias empresas en la misma instalación. App nativa (la PWA cubre móvil).

## 9. Decisiones (aprobadas)

1. **GPS**: las coordenadas van en `fichajes_gps` y se borran a los 12 meses (cron diario). El fichaje guarda para siempre solo `zona` (dentro/fuera).
2. **Responsable**: solo ve a las personas que tiene asignadas (`responsable_id`).
3. **Inspección**: usuario con contraseña y fecha de caducidad (30 días por defecto).
4. **Fuera de zona**: el fichaje se acepta y se marca `fuera`.
