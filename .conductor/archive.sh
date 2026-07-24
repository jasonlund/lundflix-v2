#!/usr/bin/env bash
set -uo pipefail

WORKSPACE="${CONDUCTOR_WORKSPACE_NAME:-$(basename "$PWD")}"
SITE="$(printf '%s' "$WORKSPACE" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '-' | sed 's/^-*//;s/-*$//')"

# Herd registers the link + TLS cert GLOBALLY (outside the worktree), so deleting
# the workspace would leave a dangling <site>.test. `herd unlink` removes both the
# link and the cert. Best-effort — a missing link must never block archiving.
if [[ "${CONDUCTOR_IS_LOCAL:-1}" == "1" ]] && command -v herd >/dev/null 2>&1; then
  herd unlink "$SITE" || true
fi

# Drop this workspace's own database (FLIX-194 per-workspace db). Safe to drop by
# name — every workspace owns a distinct `lundflix_<workspace>`, never a shared db.
# Best-effort: a missing db or unreachable server must never block archiving.
if [[ -f .env ]]; then
  DB_NAME="lundflix_${SITE//-/_}"
  DB_HOST="$(grep -E '^DB_HOST=' .env | head -1 | cut -d= -f2-)"
  DB_PORT="$(grep -E '^DB_PORT=' .env | head -1 | cut -d= -f2-)"
  DB_USER="$(grep -E '^DB_USERNAME=' .env | head -1 | cut -d= -f2-)"
  DB_PASS="$(grep -E '^DB_PASSWORD=' .env | head -1 | cut -d= -f2-)"
  mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
    ${DB_PASS:+-p"$DB_PASS"} \
    -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;" || true
fi

echo "🧹  $WORKSPACE archived — Herd site '$SITE' + db 'lundflix_${SITE//-/_}' removed"
