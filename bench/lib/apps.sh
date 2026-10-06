# shellcheck shell=bash
# The apps under test: images, users, process models, environment and how one is started. Sourced
# by bench/run and bench/bin/verify-writes (inside the runner), after they set:
#   RUST SEED ENV_FILE WORK PORT BASE CONTAINER REDIS_CONTAINER START_TIMEOUT SERVER_CPUS, log/die
#
# Every app gets --network host, --cpuset-cpus SERVER_CPUS, HTTP_PORT=$PORT, TARGET_PORT=$PORT+1,
# the parity environment (parity/.env.reference: SECRET_KEY_BASE, VAPID keys, DISABLE_SSL, ...; the
# seed's cookies, signed ids and avatar tokens depend on that SECRET_KEY_BASE) and the seed's db/
# and storage/ at /rails/storage/db and /rails/storage/files. Process models for the CPUs it gets
# (nproc(SERVER_CPUS) = 4), each following the port's own README/benchmark harness:
#   rails    config/puma.rb defaults: WEB_CONCURRENCY=ceil(4*0.666)=3 Puma workers x
#            RAILS_MAX_THREADS=5, JOB_CONCURRENCY=3, resque-pool ceil(4*0.5)=2; Thruster in front;
#            its own Redis on the host's 6379.
#   django   WEB_WORKERS=4 Uvicorn workers (one process per core; each runs a job thread). More than
#            one worker requires REDIS_URL (bin/server; README), so a redis:7-alpine sidecar runs on
#            the host network on DJANGO_REDIS_PORT (PORT+2), pinned to SERVER_CPUS. Its memory is
#            reported separately (it is not in the app's cgroup).
#   laravel  the image's fixed model: nginx (2 workers), PHP-FPM pm=static 8 children, one
#            queue:work, one Workerman cable process. bin/start chowns storage, so it runs as root.
#            CABLE_PORT=PORT+1, FPM_PORT=PORT+2.
#   laravel-frankenphp  the Laravel port on Octane + FrankenPHP worker mode (built from the local
#            campfire-laravel-frankenphp checkout): bin/start's defaults, PHP_WORKERS = 2 x CPUs
#            Octane worker threads (+ PHP_THREADS=1), bin/cable on 127.0.0.1:TARGET_PORT, queue:work.
#   laravel-frankenphp-classic  the same image with FRANKENPHP_MODE=classic: no worker, every
#            request boots Laravel, PHP_THREADS = 2 x CPUs (the same thread count).
#   express  Node cluster (src/server.js): the primary (job worker, Cable fan-out IPC) and
#            WEB_WORKERS=3 HTTP+WebSocket workers, as its bench/compare.rb runs it on 4 CPUs.
#   elixir   bin/container-start: Thruster on HTTP_PORT -> Bandit on TARGET_PORT (one BEAM, a
#            scheduler per CPU it sees, the Resque-compatible job worker inside), and its own
#            in-container Redis on 127.0.0.1:6379 (no REDIS_URL given). Same Rails-style env as
#            the Rails app, plus PORT=$PORT, as its bench/run sets them.
#   go       one `campfire server` process: its own front server on HTTP_PORT, the app on
#            127.0.0.1:TARGET_PORT, in-process jobs (JOB_CONCURRENCY=3); GOMAXPROCS = the 4 CPUs.
#   rust     one `campfire server` process, likewise (RAILS_MAX_THREADS=5 reader pool,
#            JOB_CONCURRENCY=3), as its bench/run sets them.
#   symfony  the image's defaults: FrankenPHP worker mode (threads from the CPUs it sees), the
#            cable server on 127.0.0.1:TARGET_PORT, messenger:consume.
#   symfony-classic  the same image with FRANKENPHP_MODE=classic: no worker, every request boots
#            Symfony (as under PHP-FPM), PHP_THREADS = 2 x CPUs (bin/start's default for classic,
#            the same thread count as worker mode).
# Users: every app runs as RUN_AS (default: the invoking uid:gid; root inside the runner) except
# laravel (root: its bin/start chowns storage and FPM drops to www-data). Extra env per app: <APP>_EXTRA_ENV (space-separated K=V).

ALL_APPS="rails django laravel laravel-frankenphp-classic laravel-frankenphp express elixir go rust symfony-classic symfony"
RAILS_IMAGE=${RAILS_IMAGE:-${REFERENCE_IMAGE:-campfire-reference:app}}
DJANGO_IMAGE=${DJANGO_IMAGE:-once-campfire-django:bench}
LARAVEL_IMAGE=${LARAVEL_IMAGE:-once-campfire-laravel:bench}
LARAVEL_FRANKENPHP_IMAGE=${LARAVEL_FRANKENPHP_IMAGE:-once-campfire-laravel-frankenphp:bench}
EXPRESS_IMAGE=${EXPRESS_IMAGE:-once-campfire-express:bench}
ELIXIR_IMAGE=${ELIXIR_IMAGE:-once-campfire-elixir:bench}
GO_IMAGE=${GO_IMAGE:-once-campfire-go:bench}
RUST_APP_IMAGE=${RUST_APP_IMAGE:-${RUST_IMAGE:-once-campfire-rust:bench}}
SYMFONY_IMAGE=${SYMFONY_IMAGE:-campfire-symfony:app}
REDIS_IMAGE=${REDIS_IMAGE:-redis:7-alpine}
DJANGO_REDIS_PORT=${DJANGO_REDIS_PORT:-$((PORT + 2))}
EXPRESS_WEB_WORKERS=${EXPRESS_WEB_WORKERS:-3}
RUN_AS=${RUN_AS:-$(id -u):$(id -g)}

canonical() {
  case "$1" in
    reference|rails) echo rails ;;
    django|laravel|laravel-frankenphp|laravel-frankenphp-classic|express|elixir|go|rust|symfony|symfony-classic) echo "$1" ;;
    *) die "unknown app '$1' (${ALL_APPS// /, })" ;;
  esac
}
ncpu() { taskset -c "$SERVER_CPUS" nproc; }
rails_workers() { echo $(( ($(ncpu) * 666 + 999) / 1000 )); }
django_workers() { echo "${DJANGO_WEB_WORKERS:-$(ncpu)}"; }

image_for() {
  case "$1" in
    rails) echo "$RAILS_IMAGE" ;; django) echo "$DJANGO_IMAGE" ;; laravel) echo "$LARAVEL_IMAGE" ;;
    express) echo "$EXPRESS_IMAGE" ;; elixir) echo "$ELIXIR_IMAGE" ;; go) echo "$GO_IMAGE" ;;
    rust) echo "$RUST_APP_IMAGE" ;; symfony|symfony-classic) echo "$SYMFONY_IMAGE" ;;
    laravel-frankenphp|laravel-frankenphp-classic) echo "$LARAVEL_FRANKENPHP_IMAGE" ;;
  esac
}
user_for() { if [ "$1" = laravel ]; then echo 0:0; else echo "$RUN_AS"; fi; }
# How the app protects form posts: a token from <meta name="csrf-token"> (the Rails way), or, for
# Rust (since b567772 "Check Sec-Fetch-Site instead of CSRF tokens") and Go (ported from it), the
# Sec-Fetch-Site header alone; their pages carry no token, and loadgen sends Sec-Fetch-Site anyway.
csrf_mode() { case "$1" in go|rust) echo sec-fetch-site ;; *) echo token ;; esac; }
needs_redis() { [ "$1" = django ] && [ "$(django_workers)" -gt 1 ]; }
ports_for() {
  case "$1" in
    rails|elixir) echo "$PORT $((PORT + 1)) 6379" ;;
    django)  echo "$PORT$(needs_redis django && echo " $DJANGO_REDIS_PORT")" ;;
    laravel) echo "$PORT $((PORT + 1)) $((PORT + 2))" ;;
    express) echo "$PORT" ;;
    laravel-frankenphp|laravel-frankenphp-classic|go|rust|symfony|symfony-classic) echo "$PORT $((PORT + 1))" ;;
  esac
}
process_model() {
  case "$1" in
    rails)   echo "Puma WEB_CONCURRENCY=$(rails_workers) workers x RAILS_MAX_THREADS=5, JOB_CONCURRENCY=$(rails_workers), resque-pool ceil(nproc*0.5)=$(( ($(ncpu) + 1) / 2 )) workers, Thruster, in-container Redis on :6379" ;;
    django)  if needs_redis django; then echo "Uvicorn WEB_WORKERS=$(django_workers) processes (async; a job thread in each), Redis sidecar $REDIS_IMAGE on 127.0.0.1:$DJANGO_REDIS_PORT pinned to $SERVER_CPUS"
             else echo "Uvicorn WEB_WORKERS=$(django_workers) process (async; a job thread), no Redis"; fi ;;
    laravel) echo "image defaults: nginx 2 workers, PHP-FPM pm=static 8 children, 1 queue:work, 1 Workerman cable process; CABLE_PORT=$((PORT + 1)) FPM_PORT=$((PORT + 2))" ;;
    laravel-frankenphp) echo "Octane + FrankenPHP worker mode, PHP_WORKERS = 2×CPUs; bin/cable on 127.0.0.1:$((PORT + 1)), queue:work" ;;
    laravel-frankenphp-classic) echo "FrankenPHP classic mode (no worker), PHP_THREADS = 2×CPUs; bin/cable on 127.0.0.1:$((PORT + 1)), queue:work" ;;
    express) echo "Node cluster: primary (jobs, cable IPC) + WEB_WORKERS=$EXPRESS_WEB_WORKERS HTTP/WebSocket workers on :$PORT" ;;
    elixir)  echo "bin/container-start: Thruster :$PORT -> Bandit :$((PORT + 1)) in one BEAM (schedulers = $(ncpu) CPUs, CAMPFIRE_WORKER=1 job worker), in-container Redis on :6379; Rails-style env WEB_CONCURRENCY=$(rails_workers) JOB_CONCURRENCY=$(rails_workers) RAILS_MAX_THREADS=5" ;;
    go)      echo "one campfire process: front server :$PORT, app 127.0.0.1:$((PORT + 1)), in-process jobs JOB_CONCURRENCY=$(rails_workers), GOMAXPROCS=$(ncpu)" ;;
    rust)    echo "one campfire process: front server :$PORT, app 127.0.0.1:$((PORT + 1)), RAILS_MAX_THREADS=5 reader pool, JOB_CONCURRENCY=$(rails_workers)" ;;
    symfony) echo "image defaults: FrankenPHP worker mode, campfire:cable on 127.0.0.1:$((PORT + 1)), messenger:consume" ;;
    symfony-classic) echo "FrankenPHP classic mode (no worker), PHP_THREADS = 2×CPUs; campfire:cable on 127.0.0.1:$((PORT + 1)), messenger:consume" ;;
  esac
}

# docker run arguments for an app, one per line: user, environment.
app_args() {
  local app=$1 workers e extra
  printf '%s\n' --user "$(user_for "$app")"
  # The parity environment, without the Rails process-model lines (set per app below).
  grep -Ev '^(#|$|WEB_CONCURRENCY|JOB_CONCURRENCY|RAILS_MAX_THREADS|RAILS_LOG_LEVEL)' "$ENV_FILE" | sed 's/^/-e\n/'
  printf '%s\n' -e "HTTP_PORT=$PORT" -e "TARGET_PORT=$((PORT + 1))"
  workers=$(rails_workers)
  case "$app" in
    rails|elixir|go|rust)
      # config/puma.rb's defaults for nproc(SERVER_CPUS), as the Rust and Elixir harnesses set them
      # for Rails, Elixir, Go and Rust alike (Go/Rust read JOB_CONCURRENCY and RAILS_MAX_THREADS).
      printf '%s\n' -e "WEB_CONCURRENCY=$workers" -e "JOB_CONCURRENCY=$workers" -e RAILS_MAX_THREADS=5 -e RAILS_LOG_LEVEL=warn ;;
  esac
  case "$app" in
    rails) extra=${RAILS_EXTRA_ENV:-} ;;
    django)
      printf '%s\n' -e "WEB_WORKERS=$(django_workers)" -e CAMPFIRE_STORAGE_PATH=/rails/storage
      needs_redis django && printf '%s\n' -e "REDIS_URL=redis://127.0.0.1:$DJANGO_REDIS_PORT/0"
      extra=${DJANGO_EXTRA_ENV:-} ;;
    laravel)
      printf '%s\n' -e "CABLE_PORT=$((PORT + 1))" -e "FPM_PORT=$((PORT + 2))"
      extra=${LARAVEL_EXTRA_ENV:-} ;;
    laravel-frankenphp)
      # bin/start: CABLE_PORT defaults to TARGET_PORT.
      extra=${LARAVEL_FRANKENPHP_EXTRA_ENV:-} ;;
    laravel-frankenphp-classic)
      printf '%s\n' -e FRANKENPHP_MODE=classic
      extra=${LARAVEL_FRANKENPHP_CLASSIC_EXTRA_ENV:-} ;;
    express)
      printf '%s\n' -e "WEB_WORKERS=$EXPRESS_WEB_WORKERS" -e CAMPFIRE_STORAGE_PATH=/rails/storage
      extra=${EXPRESS_EXTRA_ENV:-} ;;
    elixir)
      # DATABASE_PATH and STORAGE_PATH are the image's (/rails/storage/db/production.sqlite3, /rails/storage/files).
      printf '%s\n' -e "PORT=$PORT"
      extra=${ELIXIR_EXTRA_ENV:-} ;;
    go)
      printf '%s\n' -e CAMPFIRE_STORAGE_PATH=/rails/storage
      extra=${GO_EXTRA_ENV:-} ;;
    rust) extra=${RUST_EXTRA_ENV:-} ;;
    symfony)
      printf '%s\n' -e CAMPFIRE_STORAGE_PATH=/rails/storage
      extra=${SYMFONY_EXTRA_ENV:-} ;;
    symfony-classic)
      printf '%s\n' -e CAMPFIRE_STORAGE_PATH=/rails/storage -e FRANKENPHP_MODE=classic
      extra=${SYMFONY_CLASSIC_EXTRA_ENV:-} ;;
  esac
  for e in $extra; do printf '%s\n' -e "$e"; done
}

# Web Push and webhook deliveries to the seed's real endpoints (fcm.googleapis.com, ...) go to a
# closed local port instead, so they fail fast without leaving the box.
neuter_deliveries() {
  python3 - "$1" <<'PY'
import sqlite3, sys
db = sqlite3.connect(sys.argv[1])
db.execute("UPDATE push_subscriptions SET endpoint = 'https://127.0.0.1:9/push/' || id")
db.execute("UPDATE webhooks SET url = 'http://127.0.0.1:9/hook/' || id")
db.commit()
PY
}

busy_ports() {
  python3 - "$@" <<'PY'
import socket, sys
for p in sys.argv[1:]:
    s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    try: s.bind(("0.0.0.0", int(p)))
    except OSError: print(p, end=" ")
    finally: s.close()
PY
}

# Docker's cgroup v2 layout: systemd driver (system.slice/docker-ID.scope) or cgroupfs (docker/ID,
# OrbStack's).
cgroup_of() {
  local id; id=$(docker inspect -f '{{.Id}}' "$1")
  for d in "/sys/fs/cgroup/system.slice/docker-$id.scope" "/sys/fs/cgroup/docker/$id"; do
    [ -d "$d" ] && { echo "$d"; return; }
  done
  find /sys/fs/cgroup -maxdepth 4 -type d -name "*$id*" -print -quit
}

# "platform id revision created" of an image; the revision is its org.opencontainers.image.revision
# label, else its GIT_REVISION environment (the Rails Dockerfile's build argument).
image_source() { docker image inspect -f '{{with index .Config "Labels"}}{{with index . "org.opencontainers.image.source"}}{{.}}{{end}}{{end}}' "$1" 2>/dev/null; }
image_info() {
  local plat id lrev erev created
  IFS='|' read -r plat id lrev erev created <<<"$(docker image inspect -f '{{.Os}}/{{.Architecture}}{{with index . "Variant"}}/{{.}}{{end}}|{{.Id}}|{{with index .Config "Labels"}}{{with index . "org.opencontainers.image.revision"}}{{.}}{{end}}{{end}}|{{range .Config.Env}}{{if eq (index (split . "=") 0) "GIT_REVISION"}}{{index (split . "=") 1}}{{end}}{{end}}|{{.Created}}' "$1")"
  # An image built FROM dunglas/frankenphp (Symfony, Laravel Octane) inherits FrankenPHP's own
  # revision label unless its build sets one: that is not the app's revision.
  if [ -n "$lrev" ] && [ "$(docker image inspect -f '{{index .Config.Labels "org.opencontainers.image.title"}}' "$1")" = FrankenPHP ]; then lrev="none(label-inherited-from-FrankenPHP-${lrev:0:7})"; fi
  echo "$plat $id ${lrev:-${erev:-unknown}} $created"
}
start_redis() {
  docker run -d --name "$REDIS_CONTAINER" --network host --cpuset-cpus "$SERVER_CPUS" "$REDIS_IMAGE" \
    redis-server --port "$DJANGO_REDIS_PORT" --bind 127.0.0.1 --save '' --appendonly no >/dev/null
  for _ in $(seq 1 250); do
    docker exec "$REDIS_CONTAINER" redis-cli -p "$DJANGO_REDIS_PORT" ping 2>/dev/null | grep -q PONG && return 0
    sleep 0.02
  done
  die "redis sidecar did not answer on :$DJANGO_REDIS_PORT"
}

# Fresh copy of the seed in $WORK/APP, then a fresh container; prints ms from docker run to the
# first /up 200. Dies (with the container's last log lines) if it exits or doesn't answer in time.
start_app() {
  local app=$1; local dir=$WORK/$app busy args=()
  docker rm -f "$CONTAINER" "$REDIS_CONTAINER" >/dev/null 2>&1 || true
  # shellcheck disable=SC2046
  busy=$(busy_ports $(ports_for "$app"))
  [ -z "$busy" ] || die "$app needs ports $(ports_for "$app") on the Docker host; busy: $busy"
  rm -rf "$dir"; mkdir -p "$dir/db" "$dir/storage"
  cp -a --reflink=auto "$SEED/db/." "$dir/db/"; cp -a --reflink=auto "$SEED/storage/." "$dir/storage/"
  neuter_deliveries "$dir/db/production.sqlite3"
  [ "$(user_for "$app")" = 0:0 ] || chown -R "$(user_for "$app")" "$dir"
  mapfile -t args < <(app_args "$app")
  if needs_redis "$app"; then start_redis; fi
  sync
  local t0; t0=$(date +%s.%N)
  # --network host: no docker-proxy between the load generator and the app.
  docker run -d --name "$CONTAINER" --cpuset-cpus "$SERVER_CPUS" --network host "${args[@]}" \
    -v "$dir/db:/rails/storage/db" -v "$dir/storage:/rails/storage/files" \
    "$(image_for "$app")" >/dev/null
  local deadline=$(( $(date +%s) + START_TIMEOUT ))
  until curl -fsS -o /dev/null "$BASE/up" 2>/dev/null; do
    if [ "$(date +%s)" -ge "$deadline" ] || [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" != true ]; then
      docker logs --tail 40 "$CONTAINER" >&2 || true
      die "$app did not answer /up within ${START_TIMEOUT}s"
    fi
    sleep 0.02
  done
  python3 -c "import sys; print(round((float(sys.argv[2]) - float(sys.argv[1])) * 1000))" "$t0" "$(date +%s.%N)"
}

# Runs a read of the app's live SQLite database as the database file's owner, so a WAL -shm file
# the read may create belongs to the app's user (Laravel's FPM runs as www-data), not to root.
as_db_owner() { # db command...
  local owner; owner=$(stat -c %u:%g "$1"); shift
  if [ "$owner" = "$(id -u):$(id -g)" ]; then "$@"; else setpriv --reuid "${owner%:*}" --regid "${owner#*:}" --clear-groups "$@"; fi
}
