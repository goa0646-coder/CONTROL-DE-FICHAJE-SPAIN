# Control horario

Registro de jornada (art. 34.9 ET) para PHP 8 + MySQL en hosting compartido. Diseño: [docs/DISENO.md](docs/DISENO.md). Instalación: [INSTALAR.md](INSTALAR.md).

## Desarrollo

```bash
composer install
# MySQL con una BD vacía y desechable (las pruebas BORRAN sus tablas):
#   BD_HOST, BD_NOMBRE, BD_USUARIO, BD_CLAVE  (por defecto 127.0.0.1 / fichaje_test / test / test)
vendor/bin/phpunit
php -S localhost:8080 -t public_html   # necesita config-fichaje.php en la raíz del repo
bin/empaquetar.sh                      # crea dist/fichaje.zip
```

Reglas: los cambios de esquema van siempre en una migración nueva `public_html/app/migraciones/NNN_*.sql`. Nunca se edita una migración ya publicada.
