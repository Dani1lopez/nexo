#!/usr/bin/env bash
# Proves the migrations work from scratch, roll back completely and apply again,
# on a throwaway SQLite file outside the repository.
set -euo pipefail

cd "$(dirname "$0")/.."
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
touch "$TMP/migrations.sqlite"

export XDEBUG_MODE=off DB_CONNECTION=sqlite DB_DATABASE="$TMP/migrations.sqlite" SESSION_DRIVER=array CACHE_STORE=array

php artisan migrate:fresh --force --no-interaction
php artisan migrate:reset --force --no-interaction
php artisan migrate --force --no-interaction
php artisan migrate:status --no-interaction
