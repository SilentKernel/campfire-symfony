#!/usr/bin/env bash
# Starts an e2e container.  up.sh symfony-seed | symfony-fresh | reference-seed | down
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
key="$(grep '^SECRET_KEY_BASE=' "$root/.env.test" | cut -d= -f2-)"
run() { # name image volume port
  docker rm -f "$1" >/dev/null 2>&1 || true
  docker run -d --name "$1" -v "$3:/rails/storage" \
    -e SECRET_KEY_BASE="$key" -e DISABLE_SSL=true -e HTTP_PORT="$4" -e TARGET_PORT="$(($4 + 1))" \
    -p "$4:$4" "$2" >/dev/null
  for _ in $(seq 1 60); do
    curl -fsS -o /dev/null "http://localhost:$4/up" 2>/dev/null && { echo "$1 up on :$4"; return 0; }
    sleep 1
  done
  docker logs --tail 50 "$1"; exit 1
}
case "${1:-}" in
  symfony-seed)   run cfs-e2e campfire-symfony:app cfs-e2e-seed 8090 ;;
  symfony-fresh)  run cfs-e2e campfire-symfony:app cfs-e2e-fresh 8090 ;;
  reference-seed) run cfr-e2e campfire-reference:app cfr-e2e-seed 8092 ;;
  down)           docker rm -f cfs-e2e cfr-e2e >/dev/null 2>&1 || true ;;
  *) echo "usage: $0 symfony-seed|symfony-fresh|reference-seed|down" >&2; exit 2 ;;
esac
