#!/bin/bash
# Crea dist/fichaje.zip con el contenido de public_html + config.ejemplo.php
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist
cp config.ejemplo.php public_html/
(cd public_html && zip -qr ../dist/fichaje.zip . -x '*.DS_Store')
rm public_html/config.ejemplo.php
cp INSTALAR.md dist/
echo "dist/fichaje.zip"
