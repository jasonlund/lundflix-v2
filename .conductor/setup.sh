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

# DB — TODO(FLIX-126): provision a PER-WORKSPACE db (create + migrate + seed).
# For now all workspaces SHARE the root checkout's MySQL db (name + creds from the
# copied root .env). Setup only ensures the db EXISTS — it deliberately does NOT
# migrate: a workspace on a branch with new migrations would apply them to the
# shared db and break every other workspace. Run `php artisan migrate` by hand
# when you actually want to move the shared schema.
DB_NAME="$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2-)"
DB_HOST="$(grep -E '^DB_HOST=' .env | head -1 | cut -d= -f2-)"
DB_PORT="$(grep -E '^DB_PORT=' .env | head -1 | cut -d= -f2-)"
DB_USER="$(grep -E '^DB_USERNAME=' .env | head -1 | cut -d= -f2-)"
DB_PASS="$(grep -E '^DB_PASSWORD=' .env | head -1 | cut -d= -f2-)"
mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
  ${DB_PASS:+-p"$DB_PASS"} \
  -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan optimize:clear
echo "✅  $WORKSPACE ready → https://$SITE.test"
