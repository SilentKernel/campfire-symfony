#!/usr/bin/env bash
# FRANKENPHP_MODE smoke test: the same image in worker and classic mode must both serve the app.
#
#   tests/Container/modes.sh [IMAGE]     default campfire-symfony:app
#
# Each mode gets a fresh copy of the parity seed in a Docker volume, signs in as David, loads the
# busy room 20 times and reports the median time; classic mode must be slower per request (it
# boots the kernel every time), which proves the switch really changes the runtime.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
IMAGE=${1:-campfire-symfony:app}
SEED=${SEED:-/opt/campfire-bench/once-campfire-rust/parity/.seed/default}
PORT=${PORT:-8095}
KEY=$(grep '^SECRET_KEY_BASE=' "$ROOT/.env.test" | cut -d= -f2)
LABELS=$ROOT/var/seed/default/labels.json
label() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$LABELS" "$1"; }
ROOM=$(label rooms.watercooler)
JAR=$(mktemp)
declare -A median

cleanup() { docker rm -f campfire-modes >/dev/null 2>&1 || true; docker volume rm -f campfire-modes >/dev/null 2>&1 || true; rm -f "$JAR"; }
trap cleanup EXIT

for mode in worker classic; do
  cleanup
  docker volume create campfire-modes >/dev/null
  docker run --rm -v "$SEED:/seed:ro" -v campfire-modes:/storage alpine \
    sh -c 'mkdir -p /storage/db /storage/files && cp -a /seed/db/. /storage/db/ && cp -a /seed/storage/. /storage/files/ && chmod -R a+rwX /storage'
  docker run -d --name campfire-modes -p "$PORT:$PORT" -e FRANKENPHP_MODE=$mode -e SECRET_KEY_BASE="$KEY" \
    -e DISABLE_SSL=true -e HTTP_PORT=$PORT -e TARGET_PORT=$((PORT + 1)) -v campfire-modes:/rails/storage "$IMAGE" >/dev/null
  for _ in $(seq 120); do curl -fs -o /dev/null "localhost:$PORT/up" && break; sleep 0.5; done

  : > "$JAR"
  token=$(curl -fs -c "$JAR" "localhost:$PORT/session/new" | grep -o 'name="csrf-token" content="[^"]*"' | cut -d'"' -f4)
  login=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "email_address=$(label emails.david)" \
    --data-urlencode "password=$(label passwords.all)" --data-urlencode "authenticity_token=$token" "localhost:$PORT/session")
  times=()
  for _ in $(seq 20); do
    read -r code ms < <(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{time_total}\n' "localhost:$PORT/rooms/$ROOM")
    [ "$code" = 200 ] || { echo "$mode: room page returned $code" >&2; docker logs campfire-modes 2>&1 | tail -20 >&2; exit 1; }
    times+=("$ms")
  done
  median[$mode]=$(printf '%s\n' "${times[@]}" | sort -n | sed -n 10p)
  workers=$(docker logs campfire-modes 2>&1 | grep -ci 'worker' || true)
  echo "$mode: login $login, room page 200 x20, median ${median[$mode]}s, worker log lines $workers"
done

awk -v w="${median[worker]}" -v c="${median[classic]}" 'BEGIN { exit !(c > w) }' &&
  echo "OK: classic mode is slower per request (${median[classic]}s vs ${median[worker]}s), as a per-request boot should be" ||
  { echo "FAIL: classic mode isn't slower than worker mode" >&2; exit 1; }
