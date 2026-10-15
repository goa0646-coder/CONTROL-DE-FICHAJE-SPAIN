# Diseño — Control horario (fase 1 en detalle, fases 2–4 en esquema)

Estado: **borrador para aprobar**. No hay código todavía.

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
- **fichajes** *(solo INSERT)*: id, usuario_id, tipo (`entrada` / `salida` / `pausa_inicio` / `pausa_fin`), momento_utc, origen (`movil` / `web` / `quiosco` / `correccion`), centro_id, quiosco_id, lat, lng, precision_m, ip, correccion_id, hash_anterior, hash.
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

## 6. Fases 2–4 (solo tablas previstas, se detallan al llegar)

- **Fase 2 – Horarios y turnos**: `horarios` (plantillas semanales), `turnos` (fechas concretas), `asignaciones_horario`, `avisos`. Cron cada 15 min detecta retrasos, olvidos de salida y horas extra; aviso por email y en la app.
- **Fase 3 – Ausencias**: `tipos_ausencia`, `ausencias` (solicitud + aprobación), `saldos_vacaciones` (por año), `festivos` (por centro), vista de calendario de equipo.
- **Fase 4 – Informes**: `firmas_mensuales` (resumen mensual que el empleado firma con su contraseña: hash del contenido + fecha). PDF = página HTML imprimible ("Guardar como PDF" del navegador); Excel = CSV/XLSX simple. Sin librerías pesadas.

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

## 8. Fuera de la fase 1 (a propósito)

- Fichar sin conexión (la PWA necesita internet para fichar). Se puede añadir más tarde.
- Foto o reconocimiento facial en quiosco.
- Varias empresas en la misma instalación.
- App nativa.

## 9. Preguntas para ti

1. **Coordenadas GPS**: ¿se guardan los 4 años junto al fichaje (prueba) o se borran antes (p. ej. a los 12 meses) y queda solo "dentro / fuera del centro"? Recomiendo lo segundo (más RGPD).
2. **Responsable**: ¿ve solo a las personas que tiene asignadas, o a todo su centro?
3. **Inspección**: ¿usuario con contraseña y caducidad (propuesto) o enlace temporal?
4. **Fuera del radio del centro con GPS obligatorio**: ¿se rechaza el fichaje o se acepta marcado como "fuera de zona"? Recomiendo aceptar y marcar.
