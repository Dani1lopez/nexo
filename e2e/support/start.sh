#!/usr/bin/env bash
# Starts the real application for the e2e run on a temporary SQLite database, outside the repository.
set -euo pipefail

cd "$(dirname "$0")/../.."
: "${E2E_TMP:?E2E_TMP must point to a temporary directory}"
: "${E2E_PORT:=8010}"

node e2e/support/create-run-directory.mjs
touch "$E2E_TMP/e2e.sqlite"

export XDEBUG_MODE=off
export APP_ENV=local
export APP_DEBUG=false
export DB_CONNECTION=sqlite
export DB_DATABASE="$E2E_TMP/e2e.sqlite"
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export APP_KEY

php artisan migrate --force --no-interaction
php e2e/support/seed.php
exec php artisan serve --host=127.0.0.1 --port="$E2E_PORT" --no-reload
