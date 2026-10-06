# Benchmarks: Rails and its ports, Symfony included

This directory compares campfire-symfony with the Rails app it replaces and with every other port
listed in the once-campfire README (Django, Laravel, Express, Elixir, Go, Rust), plus two FrankenPHP
variants used for a like-for-like Laravel/Symfony comparison. Every app gets the same seed data, the
same workloads and the same four CPUs. The workloads, load generator and seed come from the harness
that produced the published once-campfire README numbers: `bench/run`, `bench/loadgen` and
`parity/.seed/default` of [once-campfire-rust](https://github.com/basecamp/once-campfire-rust).

| App (`--apps`) | What runs |
|---|---|
| `rails` | once-campfire, unmodified production image (the reference) |
| `django` | once-campfire-django |
| `laravel` | once-campfire-laravel (nginx + PHP-FPM), as published |
| `laravel-frankenphp-classic` | the Laravel port converted to FrankenPHP (local checkout `campfire-laravel-frankenphp`), `FRANKENPHP_MODE=classic` |
| `laravel-frankenphp` | the same image in worker mode (Laravel Octane) |
| `express` | once-campfire-express |
| `elixir` | once-campfire-elixir |
| `go` | once-campfire-go |
| `rust` | once-campfire-rust (the app, at its current HEAD) |
| `symfony-classic` | this repository's image, `FRANKENPHP_MODE=classic` |
| `symfony` | this repository's image (FrankenPHP worker mode) |

## How to run

Everything runs inside the Linux runner container (`bench/bin/runner`). macOS has bash 3.2 and no
taskset or cgroups. The runner uses the host's Docker engine and shares its PID and cgroup
namespaces and its network.

```sh
bench/bin/runner bench/bin/setup         # once: Rust harness + seed + loadgen + Rails images in $BENCH_HOME
bench/bin/runner bench/bin/build-ports   # once: Laravel, Django, Express, Elixir, Go and Rust images (native arm64, pinned revisions)
docker build -t campfire-symfony:app .   # the Symfony image (this repository)
(cd ../campfire-laravel-frankenphp && docker build -t once-campfire-laravel-frankenphp:bench .)

# All 11 apps (context run):
caffeinate -dims bench/bin/runner bench/run \
  --apps rails,django,laravel,laravel-frankenphp-classic,laravel-frankenphp,express,elixir,go,rust,symfony-classic,symfony \
  --reps 3 --out bench/results/$(date +%F)-m3pro-all

# The Laravel/Symfony grid on the same FrankenPHP runtime, with more reps:
caffeinate -dims bench/bin/runner bench/run \
  --apps laravel-frankenphp-classic,laravel-frankenphp,symfony-classic,symfony --reps 5 --out bench/results/$(date +%F)-m3pro-grid

bench/bin/runner bench/report bench/results/<dir>     # re-render report.md
bench/bin/runner bench/bin/verify-writes [APP ...]    # persisted-write proof, all apps by default
```

`--apps` takes any subset, in the order given (the order reverses on even reps). Each app's rep takes
about 5 minutes with the default workloads (8 routes × 26 s of HTTP, two cable sizes, uploads),
plus the wait for the load average to fall below `LOAD_MAX` (up to `LOAD_WAIT_SECS`=900 s per app):
roughly 1 hour per rep of all 11 apps, 20–25 minutes per rep of the 4-app grid, on a quiet VM. With
other containers keeping the 1-minute load above 1.5, every app waits the full 900 s; stop them, or
raise `LOAD_MAX` and say so.

A run writes `bench/results/<dir>/`:

- `env.txt`: the machine, CPU sets, workloads, and each image's platform, id, digest, build
  revision, source, process model and user.
- `<app>-<rep>.json`: raw per-rep results, with a `validation` verdict, the persisted-write check
  (`writes`) and what the measured pages contain (`pages`).
- `logs/<app>-<rep>.log`: the last 300 lines of the container's log.
- `run.log` and `uptime.log`.
- `report.md`: medians with [min–max] across reps, ×Rails factors, the page-content comparison and,
  when the four FrankenPHP apps are in the run, the Laravel vs Symfony section.

`$BENCH_HOME` (default `/opt/campfire-bench`) is on the Docker VM, not on macOS. The apps' working
copies of the seed go in `$BENCH_HOME/work`, because SQLite's WAL is not safe on virtiofs.

Smoke configuration (about 1–2 minutes per app; the numbers mean nothing):

```sh
bench/bin/runner env HTTP_SECS=1 HTTP_CONCS=16 CABLE_CLIENTS=20 CABLE_TPUT_SECS=2 UPLOAD_REPS=1 LOAD_WAIT_SECS=0 \
  bench/run --apps rails,express --reps 1 --out bench/.work/smoke
```

Knobs (environment): `SERVER_CPUS=0-3`, `LOADGEN_CPUS=4-7`, `HARNESS_CPUS=8-11`, `HTTP_SECS=8`,
`HTTP_CONCS="1 16 64"`, `CABLE_CLIENTS="100 1000"`, `CABLE_TPUT_SECS=15`, `CABLE_POSTERS=4`,
`UPLOAD_REPS=5`, `PORT=4390`, `LOAD_MAX=1.5`, `LOAD_WAIT_SECS=900`, `SUITES="http cable upload"`,
`START_TIMEOUT=180`, `WRITE_SETTLE_SECS=10`, `FAIL_FAST`, the images (`RAILS_IMAGE`, `DJANGO_IMAGE`,
`LARAVEL_IMAGE`, `LARAVEL_FRANKENPHP_IMAGE`, `EXPRESS_IMAGE`, `ELIXIR_IMAGE`, `GO_IMAGE`,
`RUST_APP_IMAGE`, `SYMFONY_IMAGE`, `REDIS_IMAGE`), `EXPRESS_WEB_WORKERS=3`, `DJANGO_WEB_WORKERS`, and
`<APP>_EXTRA_ENV="K=V ..."` (`-` written `_`, e.g. `SYMFONY_CLASSIC_EXTRA_ENV`). Run
`bench/run --help` for the full list. The apps' definitions (image, user, environment, process
model, ports) are in `bench/lib/apps.sh`, shared by `bench/run` and `bench/bin/verify-writes`.

## What each rep does

Apps run one at a time, never two at once. The order reverses on even reps. Before each app,
the harness waits until the 1-minute load average is below `LOAD_MAX`. Then it takes a fresh copy
of the seed and redirects Web Push and webhook endpoints to a closed local port. It starts a fresh
container pinned to `SERVER_CPUS` on `--network host` and measures:

| Suite | Measures | Validated (any failure marks the rep invalid in report.md) |
|---|---|---|
| startup | `docker run` → first `/up` 200; idle `memory.current`/anon after 10 s | — |
| login/scrape | signs in as david (`/session/new`, `POST /session`); reads the room page and sidebar | `session_token` cookie; room page 200 with a CSRF meta (except Go and Rust, see below), Turbo stream sources and a stylesheet |
| pages | one untimed GET of the room page, a messages page, search and the sidebar: messages shown (ids, in order), element counts, decoded/gzip/text size, hidden CSRF inputs | recorded, compared in the report (not a pass/fail) |
| http | the room page, a messages page (`?before=`), the sidebar, search (`coffee`), an image avatar, the static CSS, `/up`, and posting a message. Each gets a 2 s warm-up at c=4, then `HTTP_SECS` at each of c=1, 16, 64. Keep-alive, gzip. | every response 2xx/3xx, zero transport errors |
| writes | right after the post_message route (before the cable suite posts anything): every acknowledged post (2xx/3xx, warm-up included) | new `messages` rows == acknowledged posts == new messages whose rich text body holds `bench write N` == new messages in `message_search_index` (by rowid and by `MATCH '"bench write"'`); `PRAGMA integrity_check` ok. Read-only, on the live database, as the database file's owner; up to `WRITE_SETTLE_SECS` for asynchronous indexing |
| cable | N clients subscribe the way chatter.js does (presence, unread rooms, heartbeat, the page's stream sources). Then 30 paced posts at 200 ms apart for delivery latency, then `CABLE_POSTERS` closed-loop posters for `CABLE_TPUT_SECS` for delivered msgs/s. Per-process memory by role during each phase. | all N subscribed, none failed; every paced and saturated message reached every client |
| upload | `UPLOAD_REPS` multipart posts of `black_hole.jpg` (505 KB) | POST < 400; plus one untimed upload whose own `<img class="message__attachment">` thumbnail must come back as a smaller image |

Note on upload: in the published harness, loadgen's "thumbnail" GET follows the first `<img>` in the
response. That `<img>` is the author's **avatar**, not the attachment. Its upload timing therefore
covers the POST (including any synchronous processing) and not thumbnail generation. The extra
check in `bench/lib/validate.py` fills that gap.

`bench/bin/verify-writes` is the standalone version of the writes check: per app, a fresh seed,
the app pinned to CPUs 0-3, loadgen posting with 16 clients for 8 s (CPUs 4-7), `docker stop`, then
the same assertions on the stopped app's database. Its last output is in
`bench/results/write-verification-2026-10-06.txt`.

## Per-app configuration (4 CPUs: `--cpuset-cpus 0-3`)

All apps get the following:

- `HTTP_PORT=$PORT` (4390) and `TARGET_PORT=$PORT+1`.
- The parity environment from `parity/.env.reference`: `SECRET_KEY_BASE`, `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY`, `DISABLE_SSL=true`, `RAILS_ENV=production`, `SKIP_TELEMETRY`, `APP_VERSION`/`GIT_REVISION=parity`. The seed's session cookies, signed avatar tokens and stream names depend on this exact `SECRET_KEY_BASE`.
- The seed's `db/` at `/rails/storage/db` and its files at `/rails/storage/files`.

Each process model follows the port's own README or benchmark harness:

| App | Image | Process model | User | Notes |
|---|---|---|---|---|
| Rails | `campfire-reference:app` (once-campfire 254dd1d, unmodified production image) | `config/puma.rb` defaults for 4 CPUs, as the Rust harness sets them: `WEB_CONCURRENCY=3` Puma workers × `RAILS_MAX_THREADS=5`, `JOB_CONCURRENCY=3`, resque-pool 2 workers, Thruster in front, `RAILS_LOG_LEVEL=warn` | invoking uid (root in the runner) | its in-container Redis takes the host's port 6379 |
| Django | `once-campfire-django:bench` | `WEB_WORKERS=4` Uvicorn processes (one per CPU; each also runs the job thread). More than one worker requires `REDIS_URL` (`bin/server`, README), so a `redis:7-alpine` sidecar runs on the host network on `PORT+2`, pinned to the same CPUs 0-3 | invoking uid | `CAMPFIRE_STORAGE_PATH=/rails/storage`. The sidecar's memory is reported on its own rows, and its startup is not part of cold start. |
| Laravel | `once-campfire-laravel:bench` | the image's fixed model: nginx (2 workers), PHP-FPM `pm=static` with 8 children, 1 `queue:work`, 1 Workerman cable process | root (its `bin/start` chowns storage, and FPM drops to www-data) | `CABLE_PORT=PORT+1`, `FPM_PORT=PORT+2` |
| Laravel FrankenPHP classic | `once-campfire-laravel-frankenphp:bench` | `FRANKENPHP_MODE=classic`: no worker, Laravel boots on every request; `PHP_THREADS` = 2 × CPUs; `bin/cable` (Workerman) on `127.0.0.1:TARGET_PORT`; `queue:work` | invoking uid | same image as below |
| Laravel Octane | `once-campfire-laravel-frankenphp:bench` | `bin/start` defaults: Octane on FrankenPHP worker mode, `PHP_WORKERS` = 2 × CPUs (+1 PHP thread), `bin/cable` on `127.0.0.1:TARGET_PORT`, `queue:work` | invoking uid | its `bin/start` chowns storage to www-data when root |
| Express | `once-campfire-express:bench` | Node cluster (`src/server.js`): the primary (job worker, Cable IPC) + `WEB_WORKERS=3` HTTP/WebSocket workers, as its `bench/compare.rb` runs it on 4 CPUs | invoking uid | `CAMPFIRE_STORAGE_PATH=/rails/storage`; one port only |
| Elixir | `once-campfire-elixir:bench` | `bin/container-start`: Thruster on `HTTP_PORT` → Bandit on `TARGET_PORT` in one BEAM (a scheduler per CPU it sees, the Resque-compatible job worker inside), and its own in-container Redis on `127.0.0.1:6379` (README: "Redis starts inside the container by default"). Rails-style env (`WEB_CONCURRENCY=3`, `JOB_CONCURRENCY=3`, `RAILS_MAX_THREADS=5`, `RAILS_LOG_LEVEL=warn`) plus `PORT=$PORT`, as its `bench/run` sets them | invoking uid | takes the host's 6379, like Rails |
| Go | `once-campfire-go:bench` | one `campfire server` process: its own front server on `HTTP_PORT`, the app on `127.0.0.1:TARGET_PORT`, in-process jobs (`JOB_CONCURRENCY=3`); GOMAXPROCS = the 4 CPUs of the cpuset. Rails-style env as for Elixir (Elixir's harness runs Go that way) | invoking uid | `CAMPFIRE_STORAGE_PATH=/rails/storage` |
| Rust | `once-campfire-rust:bench` | one `campfire server` process, likewise (`RAILS_MAX_THREADS=5` reader pool, `JOB_CONCURRENCY=3`), as its own `bench/run` sets them | invoking uid | storage layout is the image's `/rails/storage` |
| Symfony classic | `campfire-symfony:app` | `FRANKENPHP_MODE=classic`: no worker, Symfony boots on every request (as under PHP-FPM), `PHP_THREADS` = 2 × CPUs; `campfire:cable` on `127.0.0.1:TARGET_PORT`, `messenger:consume` | invoking uid | `CAMPFIRE_STORAGE_PATH=/rails/storage` |
| Symfony | `campfire-symfony:app` | image defaults: FrankenPHP worker mode (thread count derived from the 4 CPUs it sees), `campfire:cable` on `127.0.0.1:TARGET_PORT`, `messenger:consume` | invoking uid | `CAMPFIRE_STORAGE_PATH=/rails/storage` |

Ports that must be free on the Docker host: 4390–4392 and 6379. The harness checks them before
each start.

**CSRF.** Rust (since b567772, "Check Sec-Fetch-Site instead of CSRF tokens") and Go (ported from
it) render no `csrf-token` meta and no `authenticity_token` inputs; they accept same-origin posts by
the `Sec-Fetch-Site` header, which loadgen always sends. For those two apps a missing token is not a
validation failure (`csrf_mode` in the rep JSON); every other app must render one.

### Revisions (all `linux/arm64`, native)

| Image | Source revision | Image id | Built |
|---|---|---|---|
| `campfire-reference:app` | basecamp/once-campfire `254dd1d46f67f2bace6c79d77f0ac026ba4c9226` | `sha256:28a60771be65…` | 2026-10-05 |
| `once-campfire-django:bench` | basecamp/once-campfire-django `7cff970b9860c952883ba5a6ed4d624194307973` (with its `reference/` submodule, which `bin/build-assets` reads) | `sha256:32be9db68264…` | 2026-10-05 |
| `once-campfire-laravel:bench` | basecamp/once-campfire-laravel `608d465904366652319fd7bcd4c727393f1b115d` | `sha256:b90afeddd8c2…` | 2026-10-05 |
| `once-campfire-express:bench` | basecamp/once-campfire-express `361330a0ecfb5abb74c8c67d243717282652aec5` (upstream HEAD 2026-10-06; Rails reference `659f957`) | `sha256:45226fee5db0…` | 2026-10-06 |
| `once-campfire-elixir:bench` | basecamp/once-campfire-elixir `f15fc9eb609286f1e3da970e7dfd95e3bf69f82f` (upstream HEAD 2026-10-06; Rails reference `90b3300`), built as described below | `sha256:b1964eabffd7…` | 2026-10-06 |
| `once-campfire-go:bench` | basecamp/once-campfire-go `8d2f7f24f56dac87dba0211b68b14d9e21e4b516` (upstream HEAD 2026-10-06; Rust reference `64f8635`, recursive submodules) | `sha256:ca8d0af681ed…` | 2026-10-06 |
| `once-campfire-rust:bench` | basecamp/once-campfire-rust `ccece30e8e160d8c3e05bf395ee55ee35962093b` (upstream HEAD 2026-10-06; Rails reference `90b3300`), in its own checkout `$BENCH_HOME/once-campfire-rust-app` | `sha256:ad5c4263480d…` | 2026-10-06 |
| `once-campfire-laravel-frankenphp:bench` | local checkout `campfire-laravel-frankenphp` (work in progress, not committed; the image carries no revision label of its own) | changes as it is rebuilt: see `env.txt` | 2026-10-06 |
| `campfire-symfony:app` | this repository (not committed yet; no revision label of its own) | changes as it is rebuilt: see `env.txt` | 2026-10-06 |
| harness, loadgen, seed | basecamp/once-campfire-rust `64f86353021145b63849fb1cd93adeb08f3b8dbb` (nothing under `bench/` or `parity/` changed up to `ccece30`) | — | 2026-10-05 |

The base images are multi-arch indexes, so they resolve to arm64: `php:8.4-fpm-bookworm@sha256:43e1ac38…`,
`composer:2.8@sha256:5248900a…`, `python:3.14.7-slim-trixie`, `node:24.21.0-bookworm-slim`,
`elixir:1.19.5-otp-28-slim@sha256:597474ac…`, `golang:1.27.1-trixie`, `rust:1.98.1-trixie`,
`debian:trixie-slim` and `ruby:3.4.10-slim`. `bench/bin/build-ports` checks that each image is
`linux/arm64` and carries the expected revision label; `bench/run` refuses any image that is not the
engine's native platform. An image built `FROM dunglas/frankenphp` inherits FrankenPHP's own
`org.opencontainers.image.revision` (233f793…); `bench/run` reports such a label as
`none(label-inherited-from-FrankenPHP-…)` rather than as the app's revision.

**Elixir: one harness-side build change.** Its Dockerfile copies Thruster out of the Rails image
from `gems/thruster-0.1.23-x86_64-linux/`, which only exists in an amd64 Rails image; on arm64 the
build would fail, or the image would need emulation to run Thruster. `bench/bin/build-ports` builds
from a copy of that Dockerfile (`$BENCH_HOME/build/elixir.Dockerfile`) in which:

- the gem directory is the native one, `thruster-0.1.23-aarch64-linux` (the same thruster 0.1.23
  gem; the reference's `Gemfile.lock` lists both platforms), and the build checks that
  `/usr/local/bin/thrust` is an aarch64 ELF;
- the frontend stage is `campfire-elixir-reference:app`, the Rails image built from Elixir's own
  pinned `reference/` (90b3300), so `campfire-reference:app` (Rails 254dd1d, the benchmarked Rails)
  stays as it is. `bin/export-assets` is run the same way, with that image name.

Otherwise the README's steps are followed unchanged: `campfire-elixir:toolchain` from
`Dockerfile.dev`, `bin/mix local.hex`, `local.rebar`, `deps.get`, then the release image. The
image carries the label `campfire.bench.dockerfile` saying so.

## This machine (Apple M3 Pro, OrbStack)

- **vCPUs are not cores.** The Docker VM has 12 vCPUs. `--cpuset-cpus 0-3` pins 4 vCPU threads, and
  macOS decides which physical P or E cores run them, possibly a different mix from moment to
  moment. The published numbers used 4 hardware threads of an AMD Ryzen AI MAX+ 395. Compare
  *ratios* between apps, not absolute numbers.
- Keep the Mac plugged in and out of Low Power Mode (both are recorded in `env.txt`). Run under
  `caffeinate -dims` and close other heavy work.
- **Stop other containers.** Anything running on the VM shares the same 12 vCPUs, and the load wait
  only waits until `LOAD_MAX` is reached. (The smoke runs below were made with about 40 unrelated
  containers running: they are correctness checks, not measurements.)
- Use 5 reps (alternating order) and compare **medians together with their [min–max] ranges**. If
  the ranges overlap, the difference is noise.
- `$BENCH_HOME` sits in OrbStack's VM root overlay, whose upper layer is on **tmpfs**:
  - It does not survive a restart of OrbStack. Re-run setup and build-ports after one.
  - `fsync` is nearly free, so SQLite commit costs are lower than on a disk. This applies equally to
    all apps.

## Smoke validation (2026-10-06)

The smoke configuration above (1 rep, `HTTP_SECS=1`, `HTTP_CONCS=16`, `CABLE_CLIENTS=20`,
`CABLE_TPUT_SECS=2`, `UPLOAD_REPS=1`) was run once for all 11 apps with the final harness (git-ignored results).

| App | Login/scrape | HTTP (8 routes) | Writes (acknowledged = new rows = rich text = FTS) | Cable, 20 clients: subscribed, paced, saturated | Upload + thumbnail |
|---|---|---|---|---|---|
| Rails | pass | pass: all 200, 0 errors | pass (543) | pass: 20/20, 30/30, 274/274 | pass: 302 → 200 JPEG |
| Django | pass | pass: all 200, 0 errors | pass (615) | **fails the saturated phase** (13/535; paced 30/30 passes) | pass: 200 JPEG |
| Laravel | pass | pass: all 200, 0 errors | pass (301) | pass: 20/20, 30/30, 141/141 | pass: 200 JPEG |
| Laravel FrankenPHP classic | pass | pass: all 200, 0 errors | pass (209) | pass: 20/20, 30/30, 137/137 | pass: 200 JPEG |
| Laravel Octane | pass | pass: all 200, 0 errors | pass (208) | pass: 20/20, 30/30, 91/91 | pass: 200 JPEG |
| Express | pass | pass: all 200, 0 errors | pass (3,627) | pass: 20/20, 30/30, 1,297/1,297 | pass: 200 JPEG |
| Elixir | pass | pass: all 200, 0 errors | pass (1,201) | pass: 20/20, 30/30, 762/762 | pass: 302 → 200 JPEG |
| Go | pass (no CSRF token: Sec-Fetch-Site) | pass: all 200, 0 errors | pass (12,305) | pass: 20/20, 30/30, 6,450/6,450 | pass: 302 → 200 JPEG |
| Rust | pass (no CSRF token: Sec-Fetch-Site) | pass: all 200, 0 errors | pass (16,014) | pass: 20/20, 30/30, 10,047/10,047 | pass: 302 → 200 JPEG |
| Symfony classic | pass | pass: all 200, 0 errors | pass (744) | pass: 20/20, 30/30, 452/452 | pass: 302 → 200 JPEG |
| Symfony | pass | pass: all 200, 0 errors | pass (3,789) | pass: 20/20, 30/30, 2,384/2,384 | pass: 302 → 200 JPEG |

One run of all 11 apps, in `bench/.work/smoke-all` (`report.md` there shows the full tables).

`bench/bin/verify-writes` (16 clients, 8 s, checked after `docker stop`) passed for all 11 apps:
see `bench/results/write-verification-2026-10-06.txt`.

## Port limitations and differences found

- **Django saturated fan-out fails** (13 of 535 messages reached every client in the smoke run). `campfire/cable.py` gives each socket an
  `asyncio.Queue(maxsize=256)`. Every broadcast, on any stream, goes into every socket's queue, and is
  then re-authorized per subscription against SQLite. When a queue fills, `QueueFull` sets
  `client.closed` and the socket is dropped. The result is the same with `DJANGO_WEB_WORKERS=1` and
  no Redis, so neither the Redis sidecar nor multiple workers cause it. The Django README claims only
  the paced workload ("all 30 messages to every socket"), and that workload passes. Django's cable
  rows therefore report a saturated throughput that is not "delivered to all", and its reps are
  marked invalid on that point.
- **The sidebar is not the same page everywhere.** On `/users/me/sidebar` Rails, Elixir, Rust and
  Symfony render about 245 elements (31 KB); Django 63, Go 84 and both Laravel images 22 (3 KB). Their
  sidebar throughput is not like-for-like; the report's page table and the Laravel/Symfony
  equivalence table flag it. Room, messages and search pages show the same 40 (13) messages in the
  same order in every app, with element counts within about 10%.
- **Wire sizes differ for a reason other than markup.** Rails and Symfony mask the CSRF token per
  form: a room page has 323 hidden inputs with 323 distinct, incompressible values, so it is about
  44 KB gzipped. The Laravel images repeat one token (644 inputs, 1 value), and their room page is
  about 20 KB for HTML of similar size (390 vs 452 KB decoded). Express, Elixir and Django are
  similar to Laravel (≈20 KB). This is visible in the "avg response bytes" rows.
- **Express memory grows under load**: about 170 MB idle, about 1.1 GB peak after the HTTP suite (4
  Node processes), and it stays there. Recorded as measured.
- **Laravel posting is slow**, in all three Laravel images (≈10–50 posts/s with high p99 latency,
  against ≈250 for Rails and Symfony classic in the same smoke conditions), but every acknowledged
  post is persisted and indexed.
- **Reference revisions differ between ports.** The benchmarked Rails is `254dd1d` (the Rust harness
  pin); Elixir and the current Rust app pin Rails `90b3300`; Express pins `659f957`; Go pins Rust
  `64f8635` (Rails `254dd1d`). Each port is measured as its authors pinned it.

## Limitations

- On these vCPUs the numbers are not comparable with the published table.
- The Django Redis sidecar sits outside the app's container cgroup. Its CPU use counts against the
  same pinned CPUs, but its memory is reported on separate rows.
- The Laravel (PHP-FPM) image has a fixed process model (8 FPM children, 2 nginx workers) whatever
  the CPU count. It is used as shipped.
- Rails and the other apps run as root inside the runner (the invoking uid there), not as their
  image's own user. This does not change any measurement.
- The loadgen upload timing measures POST → avatar, not POST → thumbnail (see above).
- The writes check in `bench/run` covers the post_message route only; the cable posters and
  uploads that follow are validated by delivery and by the thumbnail, not by a database count.

## What the Symfony image must satisfy for this harness

- Tag `campfire-symfony:app` (or set `SYMFONY_IMAGE`), built natively for `linux/arm64`.
- Start with no arguments. Front server on `HTTP_PORT`; the cable server on `127.0.0.1:TARGET_PORT`.
  No other fixed ports, because the container runs with `--network host`. Disable Caddy's admin
  endpoint (`admin off`), or accept that it occupies 2019.
- `FRANKENPHP_MODE=classic` switches off the worker (the `symfony-classic` app).
- `CAMPFIRE_STORAGE_PATH=/rails/storage` with `db/` and `files/` bind-mounted from the Docker VM.
  Anything else the app writes, such as `jobs.sqlite3`, goes under `db/` or in the container.
- Runs as an arbitrary `--user uid:gid`. Inside the runner that is `0:0`. It must not need to chown.
- Uses `SECRET_KEY_BASE`, `VAPID_*` and `DISABLE_SSL=true` from the environment. Plain HTTP must not
  set Secure cookies.
- To have its revision reported, set `org.opencontainers.image.revision` at build time and also
  override `org.opencontainers.image.title` (otherwise the FrankenPHP base's labels are taken as
  inherited).
- Behaviour that is checked:
  - `GET /up` returns 200.
  - Login: `GET /session/new` renders `<meta name="csrf-token" content=…>`, and the form POST
    returns 302 with `session_token`.
  - The room page and sidebar render `<turbo-cable-stream-source channel=… signed-stream-name=…>`
    and a stylesheet `href="/assets/…css"`.
  - Message posts accept the masked token in `X-CSRF-Token` / `authenticity_token`, and each
    acknowledged post is a `messages` row with its Action Text body and an FTS entry.
  - The upload response contains `<img class="message__attachment" src=…>`, which leads (directly
    or via redirect) to a smaller image.
  - Every cable message reaches every subscriber under 4 closed-loop posters.

## Credits

`bench/run`, `bench/report` and `bench/lib/procmem.py` are adapted from
[once-campfire-rust](https://github.com/basecamp/once-campfire-rust) (`bench/`). The load generator
(`bench/loadgen`) and the seed (`parity/.seed/default`) are used unchanged from there. Copyright (c)
37signals, LLC, MIT licence.
