# Instalar INDA en Hostinger

Necesitas: un subdominio (p. ej. `inda.miempresa.com`) con SSL activado (hPanel → Seguridad → SSL) y PHP 8.1 o superior (hPanel → Avanzado → Configuración de PHP).

## 1. Base de datos
hPanel → **Bases de datos → Bases de datos MySQL** → crea una base de datos y su usuario (p. ej. `inda`). Hostinger les pone delante tu prefijo (`u123456789_inda`). Apunta el nombre, el usuario y la contraseña.

## 2. Subir los archivos
1. hPanel → **Administrador de archivos** → entra en la carpeta del subdominio (la que tiene `public_html`).
2. Sube `inda.zip` dentro de `public_html` y descomprímelo ahí. Debe quedar `public_html/index.php`.
3. Borra `config.ejemplo.php` de `public_html` (solo es una plantilla).

## 3. Asistente
Abre `https://inda.miempresa.com` en el navegador. Como aún no hay configuración, sale el **asistente**:
1. Revisa la tabla de requisitos (✘ = hay que arreglarlo; ⚠ = funciona, pero sin esa parte).
2. Escribe los tres datos de la base de datos, la dirección y un email de tu dominio → **Probar y guardar**.
3. INDA crea `config-inda.php` **por encima** de `public_html` (fuera del alcance de la web), crea las tablas y te pide la empresa y tu usuario de administrador.
4. Entra con ese usuario.

Si el asistente dice que no puede escribir el archivo, te muestra el contenido: créalo tú con el Administrador de archivos y recarga.

Si sale un **AVISO de trigger**, tu plan no permite triggers. El sistema sigue funcionando y la página **Auditoría** detecta cualquier cambio en los fichajes.

Otras formas de crear las tablas: **phpMyAdmin** → Importar → `inda.sql` (y después abrir la web para crear el administrador), o **SSH**: `php public_html/app/migrar.php`.

## 4. Guarda la clave de instalación
En `config-inda.php` está `clave_instalacion` (la ha inventado el asistente). Te hará falta al actualizar: `https://inda.miempresa.com/?p=instalar&clave=ESA_CLAVE`.

## 5. Tarea programada cada 15 minutos
Envía los avisos (no has fichado, retrasos, jornada sin cerrar) y borra las coordenadas GPS de hace más de 12 meses.
hPanel → **Avanzado → Cron Jobs** → «Cada 15 minutos» (`*/15 * * * *`):
`/usr/bin/php /home/TU_USUARIO/domains/miempresa.com/public_html/app/cron.php`
(Ajusta la ruta a la de tu subdominio. El administrador de archivos te la muestra. Si ya tenías el cron diario, cámbialo a cada 15 minutos.)

Los emails salen con `correo_desde` de la configuración: debe ser un buzón de tu dominio creado en hPanel → Emails.

Justificantes y documentos se guardan en la carpeta `inda-archivos`, **junto a** `public_html` (no dentro). Se crea sola. Inclúyela en tus copias de seguridad.

## 6. Primeros pasos
1. **Empresa**: revisa el nombre, el CIF y las horas del convenio. Crea los centros y los departamentos. Para el GPS pon latitud y longitud (en Google Maps: clic derecho sobre el centro).
2. **Horarios**: crea los horarios (y los turnos, que son horarios con otro nombre), los festivos del año, los motivos de pausa y los tipos de ausencia.
3. **Usuarios**: da de alta a las personas con su centro, departamento, horario y responsable. Para el quiosco, ponles nº de empleado y PIN.
4. **Quioscos**: entra como admin **desde la tablet de la entrada** y pulsa «Activar quiosco aquí». La tablet muestra también un QR que cambia cada 30 s para fichar con el móvil.
5. **Cada persona en su móvil** (sin QR ni tablet): abre `https://inda.miempresa.com`, entra una vez con «Mantener la sesión abierta» marcado y pulsa **Instalar INDA** (Android) o Compartir → «Añadir a pantalla de inicio» (iPhone). Desde entonces abre INDA como una app y ficha con un toque, sin contraseña, durante un año. Si pierde el móvil: Perfil → Dispositivos → Cerrar sesión (o cambiar la contraseña). Dar de baja a la persona cierra todas sus sesiones.
6. En **Perfil → Activar avisos** cada persona recibe los avisos en el móvil.

## 7. Opcional
- **Cartel QR fijo** por centro: Empresa → Centros → «Cartel QR» → Imprimir.
- **Extensión del navegador** (Chrome/Edge): descomprime `inda-extension.zip`, abre `chrome://extensions`, activa «Modo desarrollador» → «Cargar descomprimida». Cada persona crea su código en Perfil.
- **Slack**: crea una app en api.slack.com → Slash command `/inda` con Request URL `https://inda.miempresa.com/?p=chat&canal=slack`. Copia el *Signing Secret* en `slack_secreto` de `config-inda.php`. Cada persona escribe `/inda vincular CÓDIGO` una vez.
- **Microsoft Teams**: en el equipo → Aplicaciones → Webhook saliente, URL `https://inda.miempresa.com/?p=chat&canal=teams`. Copia el token en `teams_secreto`. Se usa escribiendo `@INDA entrar`.
- **Entrar con Google o Microsoft**: registra la app en Google Cloud o Entra ID con la URL de retorno `https://inda.miempresa.com/?p=sso` y rellena `sso` en `config-inda.php`. Solo entran emails que ya existan en INDA.
- **Gestoría**: el admin crea en Perfil un código «Exportación (lectura)». API: `?p=api&accion=informe|fichajes|ausencias&desde=AAAA-MM-DD&hasta=AAAA-MM-DD` con la cabecera `Authorization: Bearer CÓDIGO`. También vale el Excel (CSV) de Informes.

## Actualizar a una versión nueva
1. Sube el ZIP nuevo y sobrescribe los archivos (tu `config-inda.php` / `config-fichaje.php` no se toca).
2. Vuelve a abrir `?p=instalar&clave=…` para aplicar los cambios de la base de datos. Las migraciones ya aplicadas no se repiten.

## Copias de seguridad
Hostinger hace copias automáticas. Además, descarga una copia de la base de datos cada mes (hPanel → phpMyAdmin → Exportar). Por ley el registro se guarda 4 años.
