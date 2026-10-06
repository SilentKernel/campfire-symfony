#!/usr/bin/env bash
# Smoke test of the production image: builds it, then runs it the way the benchmark and ONCE do
# (an arbitrary non-root uid, storage mounted at /rails/storage/{db,files}) on a fresh storage
# directory and on a copy of the parity seed (var/seed/default, see bin/fetch-seed), and checks:
#
#   * the container stays up and GET /up answers 200
#   * a compiled /assets/*.css is served with the immutable Cache-Control header, gzipped
#   * frankenphp and messenger:consume run; a killed messenger worker is restarted
#   * the ONCE pre-backup hook writes a consistent snapshot; post-restore puts it back
#   * when frankenphp dies, the container exits non-zero
#   * the image's SQLite: one library, with the WAL-reset fix (tests/Container/sqlite.sh)
#
#   tests/Container/smoke.sh               build campfire-symfony:smoke, then test it
#   SMOKE_IMAGE=name tests/Container/smoke.sh --no-build
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
IMAGE=${SMOKE_IMAGE:-campfire-symfony:smoke}
SEED=${SMOKE_SEED:-$ROOT/var/seed/default}
RUN_AS=${SMOKE_USER:-12345:12345}
CONTAINER=campfire-symfony-smoke-$$
SEED_LABELS=
[ -f "$SEED/labels.json" ] && SEED_LABELS=$SEED/labels.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/campfire-smoke.XXXXXX")

failures=0
pass() { echo "  ok    $*"; }
fail() { echo "  FAIL  $*"; failures=$((failures + 1)); }
cleanup() { docker rm -f "$CONTAINER" >/dev/null 2>&1 || true; rm -rf "$WORK"; }
trap cleanup EXIT

if [ "${1:-}" != --no-build ]; then
  started=$(date +%s)
  docker build -t "$IMAGE" "$ROOT" >&2
  echo "build: $(($(date +%s) - started))s"
fi

# Prints the PID (inside the container) of every process whose command line starts with $1.
pids_of() {
  docker exec "$CONTAINER" bash -c '
    for d in /proc/[0-9]*; do
      cmd=$(tr "\0" " " < "$d/cmdline" 2>/dev/null) || continue
      case "$cmd" in "$1"*) echo "${d#/proc/}" ;; esac
    done' _ "$1"
}

start() {
  local storage=$1
  docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
  docker run -d --name "$CONTAINER" --user "$RUN_AS" -p 127.0.0.1::8080 \
    -e SECRET_KEY_BASE=test -e DISABLE_SSL=1 -e HTTP_PORT=8080 -e CADDY_LOG_LEVEL=INFO \
    -v "$storage/db:/rails/storage/db" -v "$storage/files:/rails/storage/files" \
    "$IMAGE" >/dev/null
  PORT=$(docker port "$CONTAINER" 8080/tcp | head -1 | sed 's/.*://')
  BASE=http://127.0.0.1:$PORT
}

wait_up() {
  local deadline=$(($(date +%s) + 60)) t0
  t0=$(date +%s)
  until [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/up")" = 200 ]; do
    if [ "$(date +%s)" -ge "$deadline" ] || [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER")" != true ]; then
      docker logs --tail 40 "$CONTAINER" >&2 || true
      return 1
    fi
    sleep 0.2
  done
  echo "  (up after $(($(date +%s) - t0))s)"
}

# The request pipeline on the seed: anonymous requests are sent to sign in; a session_token cookie
# signed with the container's SECRET_KEY_BASE for a seed session gets past every filter (the
# action itself is still a 501 stub).
check_sessions() {
  local room headers token cookie
  room=$(sed -n 's/.*"rooms.watercooler": \([0-9]*\).*/\1/p' "$SEED_LABELS")
  headers=$(curl -s -o /dev/null -D - "$BASE/rooms/$room" | tr -d '\r')
  grep -qi "^location: $BASE/session/new\$" <<<"$headers" && grep -qi '^HTTP/1.1 302' <<<"$headers" &&
    pass "GET /rooms/$room anonymous: 302 to /session/new" || fail "anonymous room: $(head -1 <<<"$headers")"
  grep -qi '^set-cookie: _campfire_session=' <<<"$headers" && pass "sets _campfire_session" ||
    fail "no _campfire_session cookie"

  token=$(docker exec "$CONTAINER" sqlite3 /rails/storage/db/production.sqlite3 \
    "SELECT token FROM sessions WHERE id = $(sed -n 's/.*"sessions.david_safari": \([0-9]*\).*/\1/p' "$SEED_LABELS")")
  cookie=$(docker exec -e TOKEN="$token" "$CONTAINER" php -r '
    require "vendor/autoload.php";
    $cookies = new App\Rails\RailsCookies(new App\Rails\KeyGenerator(getenv("SECRET_KEY_BASE")));
    echo App\Rails\RailsCookies::escape($cookies->writeSigned("session_token", getenv("TOKEN"),
      App\Rails\RailsCookies::permanentExpiresAt(new DateTimeImmutable())));')
  headers=$(curl -s -o /dev/null -D - -H "Cookie: session_token=$cookie" "$BASE/rooms/$room" | tr -d '\r')
  grep -qi '^HTTP/1.1 501' <<<"$headers" && pass "minted session_token reaches the action (501 stub)" ||
    fail "minted session_token: $(head -1 <<<"$headers") $(grep -i '^location' <<<"$headers")"
  headers=$(curl -s -o /dev/null -D - -H "Cookie: session_token=$cookie" "$BASE/users/me/sidebar" -H 'X-Request-Id: smoke' | tr -d '\r')
  grep -qi '^x-request-id: smoke$' <<<"$headers" && pass "X-Request-Id echoed" || fail "X-Request-Id missing"
}

check() {
  local name=$1 storage=$2 css headers pid
  echo "== $name"
  start "$storage"

  if wait_up; then pass "GET /up 200"; else fail "GET /up never answered 200"; return; fi

  sleep 3
  [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER")" = true ] && pass "container stays up" || fail "container exited"

  css=$(docker exec "$CONTAINER" bash -c 'cd public && ls assets/*.css 2>/dev/null | head -1')
  if [ -z "$css" ]; then
    fail "no compiled CSS under public/assets"
  else
    headers=$(curl -s -o /dev/null -D - -H 'Accept-Encoding: gzip' "$BASE/$css" | tr -d '\r')
    grep -qi '^HTTP/1.1 200' <<<"$headers" && pass "/$css 200" || fail "/$css: $(head -1 <<<"$headers")"
    grep -qi '^cache-control: public, immutable, max-age=31536000$' <<<"$headers" &&
      pass "immutable Cache-Control" || fail "Cache-Control: $(grep -i '^cache-control' <<<"$headers")"
    grep -qi '^content-encoding: gzip$' <<<"$headers" && pass "gzip negotiated" || fail "no gzip Content-Encoding"
  fi

  [ -n "$(pids_of 'frankenphp run')" ] && pass "frankenphp running" || fail "frankenphp not running"
  pid=$(pids_of 'php bin/console messenger:consume' | head -1)
  if [ -n "$pid" ]; then
    pass "messenger:consume running"
    docker exec "$CONTAINER" bash -c "kill $pid"
    sleep 3
    [ -n "$(pids_of 'php bin/console messenger:consume')" ] && pass "messenger:consume restarted" ||
      fail "messenger:consume not restarted"
  else
    fail "messenger:consume not running"
  fi
  [ -n "$(pids_of 'php bin/console campfire:cable')" ] && pass "campfire:cable running" ||
    echo "  skip  campfire:cable not running (command not available yet?)"

  # FrankenPHP worker mode: the startup line reports the worker threads.
  docker logs "$CONTAINER" 2>&1 | grep -q '"worker_threads":[1-9]' && pass "FrankenPHP worker threads started" ||
    fail "no FrankenPHP worker threads in the logs (CADDY_LOG_LEVEL=INFO)"

  if [ -n "$SEED_LABELS" ] && [ "$name" != "fresh storage" ]; then
    check_sessions
  fi

  if docker logs "$CONTAINER" 2>&1 | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' >&2; then
    fail "PHP warnings in the logs"
  else
    pass "no PHP warnings in the logs"
  fi

  if [ -f "$storage/db/production.sqlite3" ]; then
    if docker exec "$CONTAINER" /hooks/pre-backup &&
      [ "$(docker exec "$CONTAINER" sqlite3 /rails/storage/backups/production.sqlite3 'PRAGMA integrity_check')" = ok ]; then
      pass "pre-backup snapshot passes integrity_check"
    else
      fail "pre-backup"
    fi
    docker exec "$CONTAINER" /hooks/post-restore && pass "post-restore" || fail "post-restore"
  fi

  for pid in $(pids_of 'frankenphp run'); do docker exec "$CONTAINER" bash -c "kill -9 $pid"; done
  local status deadline=$(($(date +%s) + 20))
  while [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER")" = true ] && [ "$(date +%s)" -lt "$deadline" ]; do
    sleep 0.5
  done
  status=$(docker inspect -f '{{.State.Running}} {{.State.ExitCode}}' "$CONTAINER")
  [[ "$status" == "false "[1-9]* ]] && pass "exits non-zero when frankenphp dies ($status)" ||
    fail "after killing frankenphp: running/exit = $status"
}

# Storage directories the arbitrary uid can write (a bind mount keeps the host's permissions).
mkdir -p "$WORK/fresh/db" "$WORK/fresh/files"
chmod -R a+rwX "$WORK/fresh"
check "fresh storage" "$WORK/fresh"

if [ -f "$SEED/db/production.sqlite3" ]; then
  mkdir -p "$WORK/seed/db" "$WORK/seed/files"
  cp -R "$SEED/db/." "$WORK/seed/db/"
  cp -R "$SEED/storage/." "$WORK/seed/files/"
  chmod -R a+rwX "$WORK/seed"
  check "seed copy ($SEED)" "$WORK/seed"
else
  echo "== seed: skipped ($SEED/db/production.sqlite3 missing; run bin/fetch-seed)"
fi

echo "== image"
echo "  size: $(docker image inspect -f '{{.Size}}' "$IMAGE" | awk '{printf "%.0f MB", $1 / 1000000}')"
docker run --rm --entrypoint sh "$IMAGE" -c \
  'echo "  $(frankenphp version)"; echo "  extensions: $(php -m | grep -v "^\[" | grep . | tr "\n" " ")"'

SQLITE_IMAGE=$IMAGE "$ROOT/tests/Container/sqlite.sh" || failures=$((failures + 1))

echo
if [ "$failures" -gt 0 ]; then
  echo "smoke: $failures failure(s)"
  exit 1
fi
echo "smoke: all checks passed"
