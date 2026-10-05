#!/usr/bin/env bash
# One-shot end-to-end run: fresh site -> start servers -> Playwright journey + PHP scenarios -> stop servers.
#
#   WP_SRC=... WC_SRC=... SQLITE_SRC=... WP_CLI="php wp-cli.phar" tests/e2e/run.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SITE_DIR="${SITE_DIR:-$(cd "$HERE/../.." && pwd)/.e2e-site}"

"$HERE/setup-site.sh"
"$HERE/start-servers.sh"
cleanup() { kill "$(cat "$SITE_DIR/site.pid")" "$(cat "$SITE_DIR/mock.pid")" 2>/dev/null || true; }
trap cleanup EXIT

(cd "$HERE" && npx playwright test)
${WP_CLI} --path="$SITE_DIR" --allow-root eval-file "$HERE/scenarios.php"

# Fail on any PHP notice/warning/error raised from this plugin's code.
if grep -iE "nplus" "$SITE_DIR/wp-content/debug.log" 2>/dev/null; then
  echo "PHP notices/warnings from nplus-sso found in debug.log" >&2
  exit 1
fi
echo "E2E OK: no PHP notices from nplus-sso."
