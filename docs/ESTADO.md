# Estado de INDA (27/11/2026)

Versión en `master`: **2026.11.27d** · Migración más alta: **030** · Pruebas: 179 en verde.
Uso real desde el **1/12/2026**, en paralelo con el sistema anterior durante la transición.

## Hecho (lo último, del más reciente al más antiguo)

- 27d · Cambio de turno con vista previa (mañana ⇄ tarde/noche) — PR #64
- 27c · Límite de intentos por conexión, 2FA por cuenta, `display_errors` off (auditoría Gemini) — PR #63
- 27b · Una sola firma en anuncios + «Lo he leído» — PR #62 · Cambios voluntarios que incumplen convenio (migración 030) — PR #61
- 26j · Motivo real de fallo de correo — PR #60 · 26i · Recuperar contraseña por SMTP — PR #59
- 26h · Borrar cuadrante entre fechas — PR #58 · 26g · Modo mantenimiento — PR #57
- Antes: calendario del trabajador en cambios/ausencias, límite de días visibles, contratos, firma con certificado PDF, cifrado DNI/NSS + 2FA, valoraciones ocultas, Protocolo Inspección, pausa automática, presencia de enfermería, sobrantes de ratio, importación de trabajadores, etc. Historial completo: `git log`.

## Pendiente

- Datos del dueño: nombres y emails de dos administradores más; formato de Nominasol; nombre oficial completo del convenio.


## Registro de trabajo (añade una línea al terminar cada tarea)

| Fecha | Agente | Qué | Versión / PR |
|---|---|---|---|
| 27/11/2026 | Claude Code | Puente entre agentes: AGENTS.md, .agents/rules, EXPERIENCIA, ESTADO, entregas/ | — |
| 27/11/2026 | Antigravity | Fix: Bugs SMTP (inyección CRLF) y tipos de ausencia consumidos sin límite anual en listado saldos | PR #67 |
| 27/11/2026 | Claude Code | Auditoría y arreglo del parche 27e: migración 031 en MariaDB, cachés con datos viejos, bolsa congelada, hora de los avisos, importar Excel, cron sin recordatorios ni Diagnóstico | 2026.11.27f |
