#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  printf 'Usage: %s <built-plugin.zip>\n' "$0" >&2
  exit 2
fi

plugin_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
archive="$(realpath "$1")"
test_dir="$plugin_dir/tests/harness"
compose="$test_dir/compose.yaml"
project="digicyfr-review-integration-$$"
port="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()')"
export DIGICYFR_TEST_PORT="$port"

python3 "$plugin_dir/scripts/release.py" --verify "$archive"
cleanup() {
  sudo -n env "DIGICYFR_TEST_PORT=$port" docker compose -p "$project" -f "$compose" down --volumes --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT
sudo -n env "DIGICYFR_TEST_PORT=$port" docker compose -p "$project" -f "$compose" up -d --wait
container="$(sudo -n env "DIGICYFR_TEST_PORT=$port" docker compose -p "$project" -f "$compose" ps -q wordpress)"
test -n "$container"
for _ in {1..90}; do
  if sudo -n docker exec "$container" test -f /var/www/html/wp-load.php; then break; fi
  sleep 1
done
sudo -n docker exec "$container" test -f /var/www/html/wp-load.php
sudo -n docker cp "$archive" "$container:/tmp/digicyfr-plugin.zip" >/dev/null
sudo -n docker cp "$test_dir/install-site.php" "$container:/tmp/digicyfr-install-site.php" >/dev/null
sudo -n docker cp "$test_dir/smoke-zip.php" "$container:/tmp/digicyfr-smoke.php" >/dev/null
sudo -n docker cp "$plugin_dir/tests/wp-integration.php" "$container:/tmp/digicyfr-wp-integration.php" >/dev/null
sudo -n docker exec -e "DIGICYFR_TEST_PORT=$port" "$container" php /tmp/digicyfr-install-site.php
sudo -n docker exec -e "DIGICYFR_PLUGIN_SLUG=digicyfr-content-review-queue" -e "DIGICYFR_TEST_PORT=$port" "$container" php /tmp/digicyfr-smoke.php
sudo -n docker exec -e "DIGICYFR_TEST_PORT=$port" "$container" php /tmp/digicyfr-wp-integration.php
