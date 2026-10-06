# Benchmarks

This benchmark compares campfire-symfony with the Rails app it replaces (`campfire-reference:app`)
and with the Django and Laravel ports. Every app gets the same seed data, the same workloads, the
same four pinned vCPUs and the same machine. The scripts are in `bench/`; [bench/README.md](../bench/README.md)
is the operator's manual.

## Method

The harness comes from [once-campfire-rust](https://github.com/basecamp/once-campfire-rust)
(revision `64f8635`). That harness produced the numbers published in the once-campfire README.

- The load generator (`bench/loadgen`) and the seed (`parity/.seed/default`, built by the Rails
  app) are used unchanged.
- `bench/run` and `bench/report` are adapted to run several apps with their own images,
  environments and process models on the same CPUs.
- The workloads and their parameters are unchanged.

The adapted version also:

- validates every response and every cable delivery;
- checks that the upload's thumbnail is really served;
- records image provenance;
- records a failed repetition instead of aborting.

**Each repetition, for each app in turn:**

- Apps never run at the same time. The order reverses on even repetitions, so no app always
  runs first or last.
- The harness waits until the 1-minute load average is below 1.5 (`LOAD_MAX`).
- It takes a fresh copy of the seed. Web Push and webhook endpoints are pointed at a closed local
  port (`127.0.0.1:9`), so deliveries fail fast without leaving the machine.
- It starts a fresh container with `--cpuset-cpus 0-3 --network host`, the parity environment
  (fixed `SECRET_KEY_BASE` and VAPID keys, `DISABLE_SSL=true`) and the seed at
  `/rails/storage/{db,files}`.
- The load generator is pinned to CPUs 4–7. The harness and its memory samplers are pinned to
  CPUs 8–11.

**Suites:**

| Suite | Measures | Validation (a failure marks the repetition invalid) |
|---|---|---|
| startup | `docker run` → first `GET /up` 200; container `memory.current` and anon memory after 10 s idle | — |
| login/scrape | sign in as david; read the room page and sidebar | `session_token` set; room page 200 with CSRF meta, Turbo stream sources and a stylesheet |
| http | room page (All Talk, 131 messages), a messages page (`?before=`), sidebar, search `coffee`, an avatar, the static CSS, `/up`, posting a message. Each gets a 2 s warm-up at 4 connections, then 8 s at 1, 16 and 64 connections. Keep-alive, gzip. | every response 2xx/3xx, no transport errors |
| cable | 100 and 1,000 clients subscribe as chatter.js does: presence, unread rooms, heartbeat, the page's stream sources. Then 30 posts paced 200 ms apart (delivery latency), then 4 closed-loop posters for 15 s (messages/s delivered to every client). Per-process memory by role during each phase. | all clients subscribed; every paced and saturated message reached every client |
| upload | 5 multipart posts of `black_hole.jpg` (505 KB) | POST < 400, plus one untimed upload whose own `<img class="message__attachment">` must return a smaller image |

The published harness's upload timing follows the first `<img>` in the response, which is the
author's avatar, not the thumbnail. So it times the POST, including any synchronous processing,
but not thumbnail generation. The extra check in `bench/lib/validate.py` covers the thumbnail.

**Reporting.** `bench/report` gives the median of the 5 repetitions with the [min–max] range for
each metric, and a ×Rails factor computed from the medians (>1 is better than Rails). Treat
differences whose ranges overlap as noise.

## Machine

- Apple M3 Pro (6 performance + 6 efficiency cores). The run is made on AC power, with Low Power
  Mode off and under `caffeinate`.
- OrbStack Docker VM with 12 vCPUs and about 16.8 GB of memory. All images are native
  `linux/arm64`; `bench/run` refuses an emulated image.
- **CPUs:** `0-3` for the app, `4-7` for the load generator, `8-11` for the harness.
- **Other containers** are stopped for the run.
- **Repetitions:** 5, alternating order.
- `env.txt` in the results directory records what was actually measured: the machine, power
  state, CPU sets, load before each run, workloads, and each image's id, digest and build
  revision.

## Per-app configuration

Each app runs its unmodified production image, with the process model it would choose for 4 CPUs:

| App | Image (revision) | Process model on 4 CPUs | User |
|---|---|---|---|
| Rails | `campfire-reference:app` (once-campfire `254dd1d`) | Puma `WEB_CONCURRENCY=3` × `RAILS_MAX_THREADS=5` (`config/puma.rb` defaults for 4 CPUs, as the Rust harness sets them), `JOB_CONCURRENCY=3`, resque-pool 2 workers, Thruster, Redis in the container | runner uid (root) |
| Django | `once-campfire-django:bench` (`7cff970`) | 4 Uvicorn workers, each with a job thread. More than one worker requires `REDIS_URL`, so a `redis:7-alpine` sidecar runs on the same CPUs. | runner uid |
| Laravel | `once-campfire-laravel:bench` (`608d465`) | the image's fixed model: nginx (2 workers), PHP-FPM `pm=static` with 8 children, 1 `queue:work`, 1 Workerman cable process | root (its `bin/start` chowns storage) |
| Symfony | `campfire-symfony:app` (this repository) | image defaults: FrankenPHP worker mode with `PHP_WORKERS` = 2 × 4 = 8 worker threads, `campfire:cable`, 1 `messenger:consume` | runner uid (root) |

`bench/bin/build-ports` builds the Django and Laravel images from their own Dockerfiles at the
pinned revisions. `bench/bin/setup` builds the Rails image at `254dd1d`.

## Results

<!-- BENCHMARK RESULTS -->

## Limitations

- **vCPUs are not cores.** `--cpuset-cpus 0-3` pins four vCPU threads of the VM. macOS decides
  which physical P or E cores run them, and the mix can change from moment to moment. Absolute
  numbers depend on that scheduling. Compare the ratios between apps, which all ran under the
  same conditions.
- **Not comparable with the published table.** The once-campfire README numbers used 4 hardware
  threads of an AMD Ryzen AI MAX+ 395 on Linux.
- **Memory-backed filesystem.** `$BENCH_HOME`, where every app's copy of the seed lives, is on
  OrbStack's VM root overlay, whose upper layer is tmpfs. `fsync` is nearly free, so SQLite
  commits cost less than on a disk. This applies to all apps equally, but it flatters
  write-heavy routes.
- The **Django Redis sidecar** is outside the app's container cgroup. Its CPU counts against the
  same pinned CPUs, but its memory is reported on separate rows. Under the 4 saturating posters,
  Django's cable server drops sockets whose 256-message queue fills. That is a limitation of the
  port, and it reproduces with one worker and no Redis. Django's saturated cable rows are
  therefore marked invalid; its paced delivery passes.
- The **Laravel image** has a fixed process model whatever the CPU count, and is used as shipped.
- Rails runs as root inside the runner rather than as its image's `rails` user. This doesn't
  change any measurement.
- **Upload** timing measures POST → avatar, not POST → thumbnail (see above).

## Reproducing

Prerequisites: Docker (OrbStack on macOS), about 3 hours with the default configuration, and no
other containers running.

```sh
bench/bin/runner bench/bin/setup         # once: Rust harness, seed, load generator, Rails images
bench/bin/runner bench/bin/build-ports   # once: Laravel and Django images
docker build -t campfire-symfony:app .

caffeinate -dims bench/bin/runner bench/run --apps rails,django,laravel,symfony --reps 5
bench/bin/runner bench/report bench/results/<stamp>   # re-render report.md
```

- A run writes `bench/results/<stamp>/`:
  - `env.txt`;
  - `<app>-<rep>.json` (raw results with a validation verdict);
  - `logs/`;
  - `run.log`, `uptime.log`;
  - `report.md`.
- `bench/README.md` lists the knobs (`SERVER_CPUS`, `HTTP_CONCS`, `CABLE_CLIENTS`, …), the
  smoke configuration and what an image must satisfy to be measured.
- `$BENCH_HOME` doesn't survive an OrbStack restart. Re-run `setup` and `build-ports` after one.
