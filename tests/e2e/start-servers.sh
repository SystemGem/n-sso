#!/usr/bin/env bash
# Starts the mock N+ server and the WordPress site (PHP built-in server) in the background.
set -euo pipefail
PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SITE_DIR="${SITE_DIR:-$PLUGIN_DIR/.e2e-site}"
SITE_PORT="${SITE_PORT:-8088}"
MOCK_PORT="${MOCK_PORT:-8099}"
export NPLUS_MOCK_STATE="${NPLUS_MOCK_STATE:-$SITE_DIR/nplus-mock-state.json}"

rm -f "$NPLUS_MOCK_STATE"
php -S "127.0.0.1:$MOCK_PORT" "$PLUGIN_DIR/tests/e2e/mock-nplus/router.php" > "$SITE_DIR/mock.log" 2>&1 &
echo $! > "$SITE_DIR/mock.pid"
PHP_CLI_SERVER_WORKERS=4 php -d memory_limit=512M -S "127.0.0.1:$SITE_PORT" -t "$SITE_DIR" > "$SITE_DIR/site.log" 2>&1 &
echo $! > "$SITE_DIR/site.pid"

for url in "http://127.0.0.1:$MOCK_PORT/__state" "http://127.0.0.1:$SITE_PORT/"; do
  for i in $(seq 1 30); do
    curl -fs -o /dev/null "$url" && break
    sleep 1
  done
done
echo "Mock N+:  http://127.0.0.1:$MOCK_PORT"
echo "WordPress: http://127.0.0.1:$SITE_PORT (admin/admin, learner/learner)"
