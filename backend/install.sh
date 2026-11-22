#!/usr/bin/env bash
# Instala el esqueleto de Laravel y superpone el código de la aplicación de esta carpeta.
# Uso (desde backend/):  ./install.sh
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp -d)"
command -v composer >/dev/null || { echo "Falta composer"; exit 1; }
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || { echo "Se requiere PHP >= 8.3"; exit 1; }

composer create-project laravel/laravel "$TMP/app" --no-interaction --prefer-dist
cd "$TMP/app"
composer require laravel/sanctum --no-interaction

# Superponer nuestros archivos sobre el skeleton (sin pisar vendor/ ni .git)
rsync -a --exclude='install.sh' --exclude='README.md' --exclude='vendor' --exclude='.git' "$HERE/" "$TMP/app/"
rm -f routes/web.php && printf '<?php\n' > routes/web.php
rm -f tests/Feature/ExampleTest.php   # espera una ruta web '/', que este proyecto (API pura) no tiene
rm -f "$TMP/app/database/database.sqlite"   # el skeleton migra SQLite al crearse: no debe quedar

# Mover el resultado a la carpeta del proyecto
rsync -a "$TMP/app/" "$HERE/"
cd "$HERE"
cp .env.example .env   # el .env del skeleton apunta a SQLite; este apunta a MySQL
php artisan key:generate
echo
echo "Listo. Siguientes pasos:"
echo "  docker compose up -d mysql   # o usa tu MySQL 8.4"
echo "  php artisan migrate"
echo "  php artisan gf:create-user tu@correo.com 'Tu Nombre'"
echo "  php artisan test"
