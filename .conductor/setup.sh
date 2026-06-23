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
# For now: share the root checkout's default sqlite file across all workspaces.
# No root db on a fresh machine -> first workspace creates + migrates it; later ones share.
touch "$ROOT/database/database.sqlite"
grep -q '^DB_DATABASE=' .env \
  && sed -i '' "s#^DB_DATABASE=.*#DB_DATABASE=$ROOT/database/database.sqlite#" .env \
  || printf 'DB_DATABASE=%s\n' "$ROOT/database/database.sqlite" >> .env
php artisan migrate --force

php artisan optimize:clear
echo "✅  $WORKSPACE ready → https://$SITE.test"
