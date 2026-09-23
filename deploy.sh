#!/usr/bin/env bash
#
# Deploys a release to the live site AND the client demo in one go.
#
# Both sites run from this one code folder, so every release reaches the
# demo automatically. They differ only in their settings and database:
#
#   live  →  .env       →  live database
#   demo  →  .env.demo  →  demo database (sample data, rebuilt here)
#
# The demo site's nginx block must pass the same three values as
# DEMO_ENV_VARS below (APP_ENV=demo plus its own config/route cache
# files), so the two sites never share a config cache — a shared one
# would point both sites at the same database.
#
# The demo steps are skipped when .env.demo does not exist, so this
# script is safe on a server that has no demo.
#
# Usage:  ./deploy.sh            (from the app folder on the server)
#
# Nightly demo refresh — add once to the web user's crontab:
#   * * * * * cd /path/to/app && APP_ENV=demo APP_CONFIG_CACHE=bootstrap/cache/config-demo.php APP_ROUTES_CACHE=bootstrap/cache/routes-demo.php php artisan schedule:run >> /dev/null 2>&1

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BRANCH="${BRANCH:-main}"
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"

DEMO_ENV_VARS=(
    APP_ENV=demo
    APP_CONFIG_CACHE=bootstrap/cache/config-demo.php
    APP_ROUTES_CACHE=bootstrap/cache/routes-demo.php
)

cd "$APP_DIR"

step() { printf '\n\033[1;32m==> %s\033[0m\n' "$1"; }

demo_artisan() {
    env "${DEMO_ENV_VARS[@]}" "$PHP" artisan "$@"
}

step "Pulling ${BRANCH}"
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

step "Installing PHP packages"
"$COMPOSER" install --no-dev --optimize-autoloader --no-interaction

if [ -f package.json ]; then
    step "Building front-end assets"
    npm ci
    npm run build
fi

step "Live: migrating the database"
"$PHP" artisan migrate --force

step "Live: rebuilding caches"
"$PHP" artisan optimize:clear
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan filament:optimize

if [ -f .env.demo ]; then
    step "Demo: rebuilding the database with today's sample data"
    # Drops and reseeds the demo database only. The command refuses to run
    # unless APP_ENV=demo and DEMO_MODE=true, so it cannot touch live data.
    demo_artisan config:clear
    demo_artisan demo-environment:refresh --force

    step "Demo: rebuilding caches"
    demo_artisan config:cache
    demo_artisan route:cache
else
    step "No .env.demo — skipping the demo site"
fi

step "Restarting queue workers"
"$PHP" artisan queue:restart

step "Done"
