#!/usr/bin/env bash
set -uo pipefail

WORKSPACE="${CONDUCTOR_WORKSPACE_NAME:-$(basename "$PWD")}"
SITE="$(printf '%s' "$WORKSPACE" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '-' | sed 's/^-*//;s/-*$//')"

# Herd registers the link + TLS cert GLOBALLY (outside the worktree), so deleting
# the workspace would leave a dangling <site>.test. `herd unlink` removes both the
# link and the cert. Best-effort — a missing link must never block archiving.
# (The shared root sqlite db is intentionally left for the other workspaces.)
if [[ "${CONDUCTOR_IS_LOCAL:-1}" == "1" ]] && command -v herd >/dev/null 2>&1; then
  herd unlink "$SITE" || true
fi

echo "🧹 $WORKSPACE archived — Herd site '$SITE' removed"
