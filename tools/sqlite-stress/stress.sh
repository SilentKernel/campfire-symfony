#!/usr/bin/env bash
# SQLite write-concurrency stress test of the production image, the way bench/run drives it:
# a fresh copy of the parity seed on the Docker VM's filesystem, the container pinned to
# SERVER_CPUS on --network host (FrankenPHP worker threads, campfire:cable, messenger:consume),
# then ROUNDS rounds of concurrent POST /rooms/<hq>/messages (loadgen, at each of POST_CONCS)
# with room-page and search GETs mixed in. Afterwards the container is stopped and both databases
# get PRAGMA integrity_check and the FTS5 'integrity-check'.
#
# Fails (exit 1) on any non-2xx/3xx response, any "malformed"/SQLITE_CORRUPT in the container
# log, or any integrity error. Runs inside the benchmark runner (it needs loadgen and the seed
# from bench/bin/setup):
#
#   bench/bin/runner tools/sqlite-stress/stress.sh
#   bench/bin/runner env ROUNDS=40 SECS=8 POST_CONCS="16 64" tools/sqlite-stress/stress.sh
#
# Knobs: IMAGE (campfire-symfony:app), ROUNDS (20), SECS (8), POST_CONCS ("16 64"),
# GET_CONC (8; 0 = no GETs), PORT (4490), SERVER_CPUS (0-3), LOADGEN_CPUS (4-7),
# STRESS_EXTRA_ENV ("K=V ..."), KEEP (keep the work copy), OUT (log directory).
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
BENCH_HOME=${BENCH_HOME:-/opt/campfire-bench}
RUST=${RUST_HOME:-$BENCH_HOME/once-campfire-rust}
SEED=$RUST/parity/.seed/default
LOADGEN=$RUST/target/bench/release/loadgen
ENV_FILE=$RUST/parity/.env.reference
IMAGE=${IMAGE:-campfire-symfony:app}
ROUNDS=${ROUNDS:-20}
SECS=${SECS:-8}
POST_CONCS=${POST_CONCS:-16 64}
GET_CONC=${GET_CONC:-8}
PORT=${PORT:-4490}
SERVER_CPUS=${SERVER_CPUS:-0-3}
LOADGEN_CPUS=${LOADGEN_CPUS:-4-7}
WORK=$BENCH_HOME/stress/$$
OUT=${OUT:-$WORK.out}
CONTAINER=campfire-sqlite-stress-$PORT
BASE=http://127.0.0.1:$PORT

log() { echo "[$(date +%T)] $*" >&2; }
die() { log "error: $*"; exit 2; }
label() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$SEED/labels.json" "$1"; }
lg() { taskset -c "$LOADGEN_CPUS" "$LOADGEN" "$@"; }
cleanup() {
  docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
  [ -n "${KEEP:-}" ] || rm -rf "$WORK"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

[ -x "$LOADGEN" ] || die "no loadgen at $LOADGEN (bench/bin/runner bench/bin/setup)"
mkdir -p "$WORK/db" "$WORK/files" "$OUT"
cp -a "$SEED/db/." "$WORK/db/"; cp -a "$SEED/storage/." "$WORK/files/"
python3 - "$WORK/db/production.sqlite3" <<'PY'
import sqlite3, sys
db = sqlite3.connect(sys.argv[1])
db.execute("UPDATE push_subscriptions SET endpoint = 'https://127.0.0.1:9/push/' || id")
db.execute("UPDATE webhooks SET url = 'http://127.0.0.1:9/hook/' || id")
db.commit()
PY
sync

env_args=()
while IFS= read -r line; do env_args+=(-e "$line"); done < <(grep -Ev '^(#|$|WEB_CONCURRENCY|JOB_CONCURRENCY|RAILS_MAX_THREADS|RAILS_LOG_LEVEL)' "$ENV_FILE")
for e in ${STRESS_EXTRA_ENV:-}; do env_args+=(-e "$e"); done
docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
docker run -d --name "$CONTAINER" --cpuset-cpus "$SERVER_CPUS" --network host --user 0:0 "${env_args[@]}" \
  -e "HTTP_PORT=$PORT" -e "TARGET_PORT=$((PORT + 1))" -e CAMPFIRE_STORAGE_PATH=/rails/storage \
  -v "$WORK/db:/rails/storage/db" -v "$WORK/files:/rails/storage/files" "$IMAGE" >/dev/null
deadline=$(($(date +%s) + 120))
until curl -fsS -o /dev/null "$BASE/up" 2>/dev/null; do
  [ "$(date +%s)" -lt "$deadline" ] || { docker logs --tail 40 "$CONTAINER" >&2; die "no /up"; }
  sleep 0.1
done

# The SQLite libraries the FrankenPHP process has mapped (there must be exactly one).
fp=$(docker exec "$CONTAINER" bash -c 'for d in /proc/[0-9]*; do case "$(tr "\0" " " < $d/cmdline 2>/dev/null)" in "frankenphp run"*) echo ${d#/proc/}; break;; esac; done')
log "image $IMAGE; frankenphp pid $fp; sqlite mappings: $(docker exec "$CONTAINER" bash -c "grep -i sqlite /proc/$fp/maps | awk '{print \$6}' | sort -u | tr '\n' ' '")"

ROOM=$(label rooms.watercooler) WRITE_ROOM=$(label rooms.hq)
login=$(lg login --base "$BASE" --email "$(label emails.david)" --password "$(label passwords.all)")
cookie=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["cookie"])' "$login")
scrape=$(lg scrape --base "$BASE" --cookie "$cookie" --room "$ROOM")
csrf=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["csrf"] or "")' "$scrape")
[ -n "$csrf" ] || die "no CSRF token"

bad=0 total=0
summ() { python3 -c 'import json,sys; r=json.loads(sys.argv[1]); s=r["statuses"]; print(sum(s.values()), sum(v for k,v in s.items() if not k.startswith(("2","3"))) + r["errors"], s)' "$1"; }
for round in $(seq 1 "$ROUNDS"); do
  for c in $POST_CONCS; do
    gets=()
    if [ "$GET_CONC" -gt 0 ]; then
      lg http --base "$BASE" --cookie "$cookie" --path "/rooms/$ROOM" --conc "$GET_CONC" --duration "$SECS" > "$OUT/get1.json" & gets+=($!)
      lg http --base "$BASE" --cookie "$cookie" --path "/searches?q=coffee" --conc "$GET_CONC" --duration "$SECS" > "$OUT/get2.json" & gets+=($!)
    fi
    res=$(lg http --base "$BASE" --cookie "$cookie" --post-room "$WRITE_ROOM" --csrf "$csrf" --conc "$c" --duration "$SECS")
    for p in "${gets[@]}"; do wait "$p"; done
    read -r n b s <<<"$(summ "$res")"
    total=$((total + n)); bad=$((bad + b))
    line="round $round c=$c posts $n bad $b $s"
    for g in get1 get2; do
      [ "$GET_CONC" -gt 0 ] || continue
      read -r gn gb _ <<<"$(summ "$(cat "$OUT/$g.json")")"; total=$((total + gn)); bad=$((bad + gb)); line+=" | $g $gn bad $gb"
    done
    log "$line"
  done
done

docker logs "$CONTAINER" > "$OUT/container.log" 2>&1 || true
corrupt=$(grep -c -E 'malformed|SQLITE_CORRUPT|General error: 11' "$OUT/container.log" || true)
docker stop -t 20 "$CONTAINER" >/dev/null
check() {
  docker run --rm --user 0:0 --entrypoint bash -v "$WORK/db:/db" "$IMAGE" -c "$1"
}
integrity=$(check '
  echo "production: $(sqlite3 /db/production.sqlite3 "PRAGMA integrity_check")"
  echo "fts5: $(sqlite3 /db/production.sqlite3 "INSERT INTO message_search_index(message_search_index) VALUES('"'"'integrity-check'"'"')" 2>&1 || true)ok"
  echo "jobs: $(sqlite3 /db/jobs.sqlite3 "PRAGMA integrity_check")"
  echo "messages: $(sqlite3 /db/production.sqlite3 "select count(*) from messages")"')
echo "$integrity" > "$OUT/integrity.txt"
log "requests $total, failed $bad, corrupt log lines $corrupt"
log "integrity: $(tr '\n' ';' <<<"$integrity")"
log "logs: $OUT"
if [ "$bad" -gt 0 ] || [ "$corrupt" -gt 0 ] || [ "$(grep -c -E '^(production|jobs): ok$|^fts5: ok$' <<<"$integrity")" -ne 3 ]; then
  log "FAIL"; exit 1
fi
log "PASS"
