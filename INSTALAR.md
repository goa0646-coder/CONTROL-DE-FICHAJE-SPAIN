# Instalar en Hostinger

Necesitas: un subdominio (p. ej. `fichaje.miempresa.com`) con SSL activado y PHP 8.1 o superior.

## 1. Base de datos
hPanel → **Bases de datos MySQL** → crea una base de datos y su usuario. Apunta el nombre, el usuario y la contraseña.

## 2. Subir los archivos
1. hPanel → **Administrador de archivos** → entra en la carpeta del subdominio (la que tiene `public_html`).
2. Sube `fichaje.zip` dentro de `public_html` y descomprímelo ahí. Debe quedar `public_html/index.php`.

## 3. Configuración (fuera de public_html)
1. En la carpeta que está **por encima** de `public_html`, crea un archivo llamado `config-fichaje.php`.
2. Copia dentro el contenido de `config.ejemplo.php` (va en el ZIP) y rellena:
   - los datos de la base de datos,
   - `url`: la dirección del subdominio, con `https://`,
   - `correo_desde`: un email de tu dominio,
   - `clave_instalacion`: una clave larga inventada (mínimo 16 caracteres).
3. Borra `config.ejemplo.php` de `public_html`.

## 4. Crear las tablas y el administrador
Abre en el navegador:
`https://fichaje.miempresa.com/?p=instalar&clave=TU_CLAVE_DE_INSTALACION`
Rellena empresa y tus datos de administrador. Después entra con ellos.

Otras formas de crear las tablas (elige una):
- **phpMyAdmin**: abre tu base de datos → Importar → `fichaje.sql`. Después abre igualmente la dirección de arriba para crear el administrador.
- **SSH**: `php public_html/app/migrar.php`.

Si sale un **AVISO de trigger**, tu plan no permite triggers. El sistema sigue funcionando y la página **Auditoría** detecta cualquier cambio en los fichajes. Avísame si pasa.

## 5. Tarea diaria (borra las coordenadas GPS de hace más de 12 meses)
hPanel → **Avanzado → Cron Jobs** → una vez al día:
`/usr/bin/php /home/TU_USUARIO/domains/miempresa.com/public_html/app/cron.php`
(Ajusta la ruta a la de tu subdominio. El administrador de archivos te la muestra.)

## 6. Primeros pasos
1. **Empresa**: revisa el nombre y el CIF. Crea los centros. Para el GPS pon latitud y longitud (en Google Maps: clic derecho sobre el centro).
2. **Usuarios**: da de alta a las personas. Para el quiosco, ponles nº de empleado y PIN.
3. **Quioscos**: entra como admin **desde la tablet de la entrada** y pulsa «Activar quiosco aquí».
4. En el móvil: abre la web → menú del navegador → «Añadir a pantalla de inicio».

## Actualizar a una versión nueva
1. Sube el ZIP nuevo y sobrescribe los archivos (tu `config-fichaje.php` no se toca).
2. Vuelve a abrir `?p=instalar&clave=…` para aplicar los cambios de la base de datos. Las migraciones ya aplicadas no se repiten.

## Copias de seguridad
Hostinger hace copias automáticas. Además, descarga una copia de la base de datos cada mes (hPanel → phpMyAdmin → Exportar). Por ley el registro se guarda 4 años.
