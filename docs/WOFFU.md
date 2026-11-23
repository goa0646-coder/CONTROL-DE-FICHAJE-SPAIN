# INDA frente a Woffu — análisis y plan

Fuentes: web y centro de ayuda públicos de Woffu, Capterra, G2, App Store y comparativas (nov. 2026). Sin acceso a una cuenta real: no se han visto sus pantallas por dentro. Las herramientas `replica-recon` / `replica-diff` no estaban disponibles en esta sesión.
INDA copia **funciones y flujos**, no logo, nombres ni textos.

## 1. Módulos de Woffu y cómo trabajan

| Módulo | Qué hace / flujo |
|---|---|
| **Fichaje** | Web, app, extensión de navegador, PIN, QR, tablet/terminal, biométrico (ZKTeco, Biostar), WhatsApp, Slack, Teams. Entrada/salida/pausas con **motivos de pausa** (comida, médico…). Teletrabajo. |
| **Límites de fichaje** | Por centro: GPS (lat, lng, radio, "solo en coordenadas") y por IP. Mensaje "fichaje no permitido desde tu ubicación". |
| **Horarios y calendarios** | Horario (plantilla semanal, flexible o fijo, franjas de entrada), calendario de festivos por persona/centro, convenio (horas anuales). Planificación masiva de cambios de horario por persona/dpto/centro y fechas. |
| **Turnos** | Calendario de turnos por equipo: fijos y rotativos, publicación al empleado. Sin planificación automática (queja). |
| **Categorización de horas** | Compara horas teóricas vs. reales: jornada efectiva, extra, nocturnas, festivas, etc. |
| **Saldos / bolsa de horas** | Saldo + / − de horas y bolsas de ausencias (asignado, comprometido, consumido, disponible). Horas extra pagadas o a compensar. |
| **Ausencias y vacaciones** | Tipos configurables (vacaciones, asuntos propios, médico, baja…), por días o **por franja horaria**, adjunto (justificante), flujo de aprobación por responsable, saldo anual, arrastre. |
| **Calendario de equipo** | Quién está, quién falta, solapes antes de aprobar. |
| **Responsable** | Ver y validar fichajes del equipo, aprobar solicitudes, alertas. |
| **Avisos y notificaciones** | Recordatorio de fichar, olvido de salida, retrasos, solicitudes pendientes (email/push). |
| **Informes** | Usuarios, actividad, presencia diaria, fichajes, saldos; avanzados y plantillas propias (planes altos). Exportar Excel/PDF. Informe para Inspección. |
| **Documentos y firma** | Gestor documental (nóminas, contratos), firma (Signaturit), envío masivo. |
| **Portal del empleado** | Perfil, datos, documentos, solicitudes, calendario, comunicados. |
| **Organización** | Empresa, centros, departamentos, roles/permisos, responsables. |
| **Integraciones** | API, A3, Sage, SAP, Meta4, Personio, SSO Microsoft/Google. |
| **Otros (planes altos)** | Accesos, canal de denuncias, selección, evaluación del desempeño. |

### Quejas de usuarios (lo que INDA debe hacer mejor)
- El fichaje a veces se queda colgado o hay que refrescar para ver si entró → INDA: confirmación inmediata y visible, reintento.
- La app móvil tiene menos que la web → INDA: una sola web responsive, todo igual en el móvil.
- Corregir un fichaje olvidado es pesado → INDA: corrección en 2 toques desde el día.
- Poca flexibilidad con horarios variables → horarios flexibles y por franjas.
- Informes poco claros / poco personalizables → informes simples con totales claros.
- Soporte lento → ayuda dentro de la app y textos claros (el soporte es interno).
- Se olvidan de fichar → recordatorios.
- Sin planificación automática de turnos → plantillas rotativas que se repiten solas.

## 2. Tabla de funciones (actualizada tras las fases 2–7)

| Función de Woffu | ¿INDA la tiene? | Esfuerzo |
|---|---|---|
| Fichaje web / móvil (PWA) | Sí | — |
| Pausas con motivo (retribuida o no) | Sí | — |
| Fichaje teletrabajo marcado | No (descartado) | — |
| Quiosco/tablet con PIN | Sí | — |
| Fichaje por QR | Sí (QR del quiosco cada 30 s y cartel fijo) | — |
| Extensión de navegador | Sí (Chrome/Edge) | — |
| Slack / Teams | Sí | — |
| WhatsApp | No (cuenta de pago de Meta) | Alto |
| Biométrico (ZKTeco…) | No (hardware + RGPD) | Alto |
| Límite por GPS y radio, bloquear fuera de zona | Sí | — |
| Límite por IP | Sí | — |
| Correcciones con aprobación | Sí | — |
| Responsable valida equipo / equipo en tiempo real | Sí | — |
| Inspección / auditoría / inmutabilidad | Sí (mejor: cadena de hashes) | — |
| Departamentos | Sí | — |
| Horarios fijos y flexibles | Sí | — |
| Festivos por empresa y centro | Sí | — |
| Convenio (horas anuales) | Sí (referencia en informes) | — |
| Planificación masiva de horarios | Sí | — |
| Turnos fijos y rotativos + cuadrante | Sí | — |
| Horas teóricas vs. reales, extra, nocturnas, festivas | Sí | — |
| Bolsa de horas con ajustes | Sí | — |
| Vacaciones y ausencias por días y por horas | Sí | — |
| Justificantes adjuntos | Sí | — |
| Saldos anuales y arrastre | Sí | — |
| Calendario de equipo con aviso de solapes | Sí | — |
| Avisos: no fichado, retraso, olvido de salida, solicitudes | Sí (app + email + push) | — |
| Informes de presencia / horas / saldos / ausencias | Sí | — |
| Excel y PDF | Sí (CSV para Excel; PDF al imprimir) | — |
| Resumen mensual firmado por el empleado | Sí | — |
| Documentos (nóminas, contratos) con firma | Sí (confirmación con contraseña + hash) | — |
| Comunicados con control de lectura | Sí | — |
| Perfil del empleado | Sí | — |
| Permisos por rol configurables | Sí | — |
| Entrar con Google / Microsoft | Sí | — |
| API / exportación para gestoría | Sí (JSON + CSV) | — |
| Varios idiomas | No (plantilla en español) | Medio |
| Accesos, denuncias, selección, desempeño | No (fuera de control horario) | Alto |

## 3. Plan propuesto (por uso diario)

**Fase 2 — Día a día (lo que se usa cada jornada)**
1. Motivos de pausa. ~~Marca de teletrabajo~~ (descartada).
2. Departamentos.
3. Horarios semanales (fijo/flexible) y festivos por centro.
4. Horas teóricas vs. reales y saldo de horas (bolsa).
5. Avisos por email: recordatorio de fichar, olvido de salida, retraso (cron cada 15 min).
6. Opción por centro: bloquear fuera de zona / limitar por IP.

**Fase 3 — Ausencias y vacaciones**
1. Tipos de ausencia, solicitud por días o por horas, justificante, aprobación.
2. Saldos anuales con arrastre.
3. Calendario de equipo (avisa de solapes al aprobar).

**Fase 4 — Informes y firma**
1. Informes: presencia diaria, horas/extra, saldos, ausencias. Excel (CSV/XLSX) y PDF imprimible.
2. Resumen mensual que el empleado firma (hash + fecha).
3. Exportación para la gestoría.

**Fase 5 — Turnos**
1. Turnos y plantillas rotativas que se repiten solas; cuadrante semanal; publicación al empleado.

**Fase 6 — Portal del empleado**
1. Documentos (nóminas, contratos) con acuse/firma simple.
2. Comunicados.
3. Notificaciones push (PWA).

**Fase 7 — Opcional**
QR, extensión de navegador, SSO, roles a medida, Slack/Teams, biométricos, idiomas.

Cada fase: migración nueva, pruebas PHPUnit, ZIP limpio. **Aprobado (sin teletrabajo) e implementado (fusionado en `master`).**

## Fuentes
- https://go.woffu.com/software-gestion-tiempo
- https://woffu.com/en/woffu-onboarding/ · https://woffu.com/en/integrations/
- https://woffu.my.site.com/help/s/article/limitacion-en-los-fichajes?language=es
- https://woffu.my.site.com/help/s/article/geolocalizacion-gestiona-los-fichajes-de-tus-empleados-as?language=es
- https://woffu.my.site.com/help/s/article/app-woffu-para-fichaje-desde-whatsapp?language=es
- https://woffu.my.site.com/help/s/article/los-planes-de-woffu?language=es
- https://woffu.my.site.com/help/s/article/hacer-solicitud-por-franja-horaria?language=es
- https://woffu.my.site.com/help/s/article/la-categorizacion-de-horas-en-woffu?language=es
- https://woffu.my.site.com/help/s/article/informe-de-saldos?language=es
- https://woffu.my.site.com/help/s/article/Planificar-los-horarios?language=es
- https://woffu.my.site.com/help/s/article/como-gestionar-el-fichaje-de-mi-equipo?language=es
- https://www.capterra.com/p/156162/Woffu/reviews/ · https://www.g2.com/products/woffu/reviews
- https://www.jibble.io/es/resenas/woffu · https://www.tramitapp.com/blog/alternativa-a-woffu/ · https://factorial.es/blog/woffu-alternativas-control-horario/
- https://www.namirial.com/pt/partners/marketplace/woffu/
