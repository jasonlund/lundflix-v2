#!/usr/bin/env bash
set -euo pipefail

WORKSPACE="${CONDUCTOR_WORKSPACE_NAME:-$(basename "$PWD")}"
ROOT="${CONDUCTOR_ROOT_PATH:-$PWD}"
SITE="$(printf '%s' "$WORKSPACE" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '-' | sed 's/^-*//;s/-*$//')"

# .env already copied by Conductor (default .env* Files-to-copy, from root checkout, secrets included). Fallback only.
[[ -f .env ]] || cp "$ROOT/.env" .env

composer install --no-interaction --prefer-dist
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force   # root .env ships empty

# `npm ci` (not `install`) — installs strictly from the lockfile and never
# rewrites it. `npm install` would rewrite package-lock.json's "name" to the
# workspace dir and drop optional deps, polluting the diff in every workspace.
npm ci --ignore-scripts
npm run build

php artisan storage:link

# Herd: per-workspace HTTPS domain (local only; cloud workspaces skip)
if [[ "${CONDUCTOR_IS_LOCAL:-1}" == "1" ]] && command -v herd >/dev/null 2>&1; then
  herd link "$SITE"
  herd secure "$SITE"
  sed -i '' "s#^APP_URL=.*#APP_URL=https://$SITE.test#" .env
fi

# DB — one isolated database PER workspace (FLIX-194). A shared db would let any
# branch's migrations rewrite every other workspace's schema, so each workspace
# gets its own `lundflix_<workspace>` and provisions it end to end: create →
# migrate → Laravel seed (test user + settings from env) → import the committed
# version-controlled catalog dumps. (Underscore the site slug — hyphens are awkward
# in an unquoted db name.)
DB_NAME="lundflix_${SITE//-/_}"
DB_HOST="$(grep -E '^DB_HOST=' .env | head -1 | cut -d= -f2-)"
DB_PORT="$(grep -E '^DB_PORT=' .env | head -1 | cut -d= -f2-)"
DB_USER="$(grep -E '^DB_USERNAME=' .env | head -1 | cut -d= -f2-)"
DB_PASS="$(grep -E '^DB_PASSWORD=' .env | head -1 | cut -d= -f2-)"

# Point the workspace .env at its own db (override the value copied from root).
# Temp-file rewrite, not `sed -i` — portable across macOS (BSD) and cloud (GNU).
_env_tmp="$(mktemp)"
if grep -q '^DB_DATABASE=' .env; then
  sed "s#^DB_DATABASE=.*#DB_DATABASE=$DB_NAME#" .env > "$_env_tmp"
else
  cat .env > "$_env_tmp"
  printf 'DB_DATABASE=%s\n' "$DB_NAME" >> "$_env_tmp"
fi
mv "$_env_tmp" .env

mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
  ${DB_PASS:+-p"$DB_PASS"} \
  -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate --force
php artisan db:seed --force
php artisan db:import

php artisan optimize:clear
echo "✅  $WORKSPACE ready → https://$SITE.test"
