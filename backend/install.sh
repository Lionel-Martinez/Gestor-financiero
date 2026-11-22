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
rm -rf "$TMP/app/database/migrations/0001_01_01_000000_create_users_table.php.orig" 2>/dev/null || true

# Mover el resultado a la carpeta del proyecto
rsync -a "$TMP/app/" "$HERE/"
cd "$HERE"
cp -n .env.example .env
php artisan key:generate
echo
echo "Listo. Siguientes pasos:"
echo "  docker compose up -d mysql   # o usa tu MySQL 8.4"
echo "  php artisan migrate"
echo "  php artisan gf:create-user tu@correo.com 'Tu Nombre'"
echo "  php artisan test"
