#!/bin/bash
# Crea dist/inda.zip con el contenido de public_html + config.ejemplo.php
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist
cp config.ejemplo.php public_html/
(cd public_html && zip -qr ../dist/inda.zip . -x '*.DS_Store')
rm public_html/config.ejemplo.php
cp INSTALAR.md dist/
(cd extension && zip -qr ../dist/inda-extension.zip .)
# inda.sql: todas las migraciones en un archivo, para importar con phpMyAdmin.
{
  echo "CREATE TABLE IF NOT EXISTS migraciones (version VARCHAR(100) PRIMARY KEY, aplicada_utc DATETIME NOT NULL) ENGINE=InnoDB;"
  for f in public_html/app/migraciones/*.sql; do
    cat "$f"; echo
    echo "INSERT INTO migraciones VALUES ('$(basename "$f" .sql)', UTC_TIMESTAMP());"
  done
} > dist/inda.sql
echo "dist/inda.zip dist/inda.sql dist/inda-extension.zip"
