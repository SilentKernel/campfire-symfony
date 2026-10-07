# Benchmarks

This benchmark compares campfire-symfony with the Rails app it replaces (`campfire-reference:app`)
and with every port listed in the once-campfire README: Django, Laravel, Express, Elixir, Go and
Rust. Two more variants make a like-for-like Laravel/Symfony comparison possible: the Laravel port
on FrankenPHP, in classic mode and with Octane, and this image in FrankenPHP classic mode. Every app
gets the same seed data, the same workloads, the same four pinned vCPUs and the same machine. The
scripts are in `bench/`; [bench/README.md](../bench/README.md) is the operator's manual.

Results (2026-10-06):

- [`bench/results/2026-10-06-m3pro-all/report.md`](../bench/results/2026-10-06-m3pro-all/report.md):
  all 11 apps, 3 repetitions.
- [`bench/results/2026-10-06-m3pro-grid/report.md`](../bench/results/2026-10-06-m3pro-grid/report.md):
  Laravel and Symfony on the same FrankenPHP runtime, classic and worker mode, 5 repetitions.

Each directory also has the raw per-repetition JSON, `env.txt` (machine, CPU sets, image ids and
digests, process models), the container logs, `run.log` and `uptime.log`. The numbers below come
from those two reports. **They are not comparable with the AMD Ryzen AI MAX+ 395 table in the
once-campfire README** (see [Limitations](#limitations)).

## Method

The harness comes from [once-campfire-rust](https://github.com/basecamp/once-campfire-rust)
(revision `64f8635`). That harness produced the numbers published in the once-campfire README.

- The load generator (`bench/loadgen`) and the seed (`parity/.seed/default`, built by the Rails
  app) are used unchanged.
- `bench/run` and `bench/report` are adapted to run several apps with their own images,
  environments and process models on the same CPUs.
- The workloads and their parameters are unchanged.

The adapted version also:

- validates every response, every acknowledged write and every cable delivery;
- checks that the upload's thumbnail is really served;
- records what each measured page contains, so that the work behind a request can be compared;
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
| login/scrape | sign in as david; read the room page and sidebar | `session_token` set; room page 200 with CSRF meta (not for Go and Rust, which check `Sec-Fetch-Site` instead), Turbo stream sources and a stylesheet |
| pages | one untimed GET of the room page, a messages page, search and the sidebar: messages shown, elements, decoded/gzip/text size, hidden CSRF inputs | recorded and compared, not pass/fail |
| http | room page (All Talk, 131 messages), a messages page (`?before=`), sidebar, search `coffee`, an avatar, the static CSS, `/up`, posting a message. Each gets a 2 s warm-up at 4 connections, then 8 s at 1, 16 and 64 connections. Keep-alive, gzip. | every response 2xx/3xx, no transport errors |
| writes | right after the post_message route | every acknowledged post is a new `messages` row with its rich text body and an FTS entry (by rowid and by `MATCH`); `PRAGMA integrity_check` ok |
| cable | 100 and 1,000 clients subscribe as chatter.js does: presence, unread rooms, heartbeat, the page's stream sources. Then 30 posts paced 200 ms apart (delivery latency), then 4 closed-loop posters for 15 s (messages/s delivered to every client). Per-process memory by role during each phase. | all clients subscribed; every paced and saturated message reached every client |
| upload | 5 multipart posts of `black_hole.jpg` (505 KB) | POST < 400, plus one untimed upload whose own `<img class="message__attachment">` must return a smaller image |

The published harness's upload timing follows the first `<img>` in the response, which is the
author's avatar, not the thumbnail. So it times the POST, including any synchronous processing,
but not thumbnail generation. The extra check in `bench/lib/validate.py` covers the thumbnail.

**Reporting.** `bench/report` gives the median of the repetitions with the [min–max] range for
each metric, and a ×Rails factor computed from the medians (>1 is better than Rails). Treat
differences whose ranges overlap as noise. The tables below give medians; the reports have the
ranges.

## Machine

- Apple M3 Pro (6 performance + 6 efficiency cores), macOS 26.7.1. Both runs were made on AC
  power, with Low Power Mode off and under `caffeinate`.
- OrbStack Docker VM with 12 vCPUs and about 16.8 GB of memory. All images are native
  `linux/arm64`; `bench/run` refuses an emulated image.
- **CPUs:** `0-3` for the app, `4-7` for the load generator, `8-11` for the harness.
- **Other containers** were stopped: `env.txt` records one running container before each run.
- **Repetitions:** 3 for the 11-app run, 5 for the 4-app Laravel/Symfony run, alternating order.

## Per-app configuration

Each app runs its production image, with the process model its own README or benchmark harness
uses on 4 CPUs. Image ids, digests and revisions are in each run's `env.txt`;
[bench/README.md](../bench/README.md#per-app-configuration-4-cpus---cpuset-cpus-0-3) has the
details and how each image is built.

| App | Image (revision) | Process model on 4 CPUs |
|---|---|---|
| Rails | `campfire-reference:app` (once-campfire `254dd1d`) | Puma `WEB_CONCURRENCY=3` × `RAILS_MAX_THREADS=5`, `JOB_CONCURRENCY=3`, resque-pool 2 workers, Thruster, Redis in the container |
| Django | `once-campfire-django:bench` (`7cff970`) | 4 Uvicorn workers, each with a job thread; a `redis:7-alpine` sidecar on the same CPUs (more than one worker requires it) |
| Laravel | `once-campfire-laravel:bench` (`608d465`, as published) | the image's fixed model: nginx (2 workers), PHP-FPM `pm=static` with 8 children, 1 `queue:work`, 1 Workerman cable process |
| Laravel FrankenPHP classic | `once-campfire-laravel-frankenphp:bench` (pending pull request, see below) | `FRANKENPHP_MODE=classic`: no worker, Laravel boots on every request; `PHP_THREADS` = 2 × CPUs; Workerman cable process; `queue:work` |
| Laravel Octane | the same image | Octane on FrankenPHP worker mode, `PHP_WORKERS` = 2 × CPUs; Workerman cable process; `queue:work` |
| Express | `once-campfire-express:bench` (`361330a`) | Node cluster: the primary (jobs, cable IPC) + 3 HTTP/WebSocket workers |
| Elixir | `once-campfire-elixir:bench` (`f15fc9e`) | Thruster → Bandit in one BEAM (a scheduler per CPU, the job worker inside), Redis in the container |
| Go | `once-campfire-go:bench` (`8d2f7f2`) | one process: front server, app, in-process jobs (`JOB_CONCURRENCY=3`), `GOMAXPROCS=4` |
| Rust | `once-campfire-rust:bench` (`ccece30`) | one process: front server, app, 5 reader connections, `JOB_CONCURRENCY=3` |
| Symfony classic | `campfire-symfony:app` (this repository) | `FRANKENPHP_MODE=classic`: no worker, Symfony boots on every request, as under PHP-FPM; `PHP_THREADS` = 2 × CPUs; `campfire:cable`; 1 `messenger:consume` |
| Symfony | `campfire-symfony:app` (this repository) | image defaults: FrankenPHP worker mode, `PHP_WORKERS` = 2 × CPUs; `campfire:cable`; 1 `messenger:consume` |

Every container runs as `0:0` inside the runner. The two FrankenPHP pairs run the same PHP 8.4,
the same thread counts and the same SQLite 3.53.4.

**The Laravel FrankenPHP image** is built from a local checkout of once-campfire-laravel that is
the subject of an upcoming pull request to
[basecamp/once-campfire-laravel](https://github.com/basecamp/once-campfire-laravel). It adds
Octane worker mode, a `FRANKENPHP_MODE` switch, SQLite 3.53.4, and `synchronous=NORMAL`, which is
what Rails sets. **The published Laravel image** (the "Laravel" column) still opens SQLite with
`synchronous=FULL`, SQLite's default: every commit waits for an fsync while holding the write lock,
and the queue worker commits several times per job. That is why it posts only 39 messages/s at 16
clients, against 226 for the same port on FrankenPHP in classic mode.

## Results

### Validation

| App | Repetitions passed | What failed |
|---|---|---|
| Rails | 3 / 3 | — |
| Django | 0 / 3 | saturated cable fan-out at 100 and 1,000 clients (e.g. rep 1: 7 of 4,167 and 1 of 3,387 messages reached every client) |
| Laravel | 0 / 3 | saturated cable fan-out at 1,000 clients (334–338 of 409–500 messages reached every client) |
| Laravel FrankenPHP classic | 0 / 3 (all run), 0 / 5 (grid run) | saturated cable fan-out at 1,000 clients (369–375 of 2,298–2,394) |
| Laravel Octane | 0 / 3 (all run), 0 / 5 (grid run) | saturated cable fan-out at 100 clients (3,838–3,918 of 7,312–7,466) and 1,000 clients (373–379 of 7,093–7,283) |
| Express | 2 / 3 | rep 3: saturated cable fan-out at 100 clients (6,437 of 20,723) |
| Elixir | 3 / 3 | — |
| Go | 3 / 3 | — |
| Rust | 3 / 3 | — |
| Symfony classic | 3 / 3 (all run), 5 / 5 (grid run) | — |
| Symfony | 3 / 3 (all run), 5 / 5 (grid run) | — |

- **Symfony and Symfony classic passed every check in all 8 repetitions**: every HTTP response
  2xx/3xx with no transport error, every acknowledged post persisted with its rich text body and
  FTS entry (`integrity_check` ok), every paced and saturated cable message delivered to every one
  of the 100 and 1,000 clients, and the thumbnail served.
- **No app had an HTTP error or a lost write.** Every app's acknowledged posts were all persisted
  and indexed, and every app delivered the 30 paced cable messages to every client.
- The failures are in the saturated cable phase only, and come from the ports' designs.
  - Django gives each socket a queue of 256 messages and drops the socket when it fills. This
    reproduces with one worker and no Redis.
  - The Laravel cable server (all three images) learns about broadcasts by polling a file every
    20 ms, and takes 16–20 s to subscribe 1,000 clients.
  - For these apps the saturated "delivered to all" rows count only what reached every client.

### HTTP throughput (11 apps, requests/sec)

Medians of 3 repetitions. The 16-client rows are the ones the once-campfire README publishes.

| Route, clients | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Room page, c=1 | 113 | 78.2 | 33.8 | 32.8 | 46.9 | 212 | 321 | 1,036 | 6,953 | 48.8 | 209 |
| Room page, c=16 | 254 | 249 | 124 | 120 | 179 | 615 | 460 | 4,169 | 22,541 | 174 | 713 |
| Room page, c=64 | 233 | 241 | 123 | 120 | 210 | 633 | 518 | 4,105 | 22,917 | 174 | 718 |
| Messages page, c=1 | 210 | 89.4 | 38.2 | 36.7 | 60.4 | 274 | 399 | 1,301 | 7,333 | 97.5 | 538 |
| Messages page, c=16 | 439 | 289 | 142 | 137 | 221 | 797 | 716 | 5,236 | 23,907 | 350 | 1,620 |
| Messages page, c=64 | 423 | 281 | 142 | 136 | 222 | 808 | 715 | 5,158 | 24,656 | 349 | 1,734 |
| Sidebar, c=1 | 276 | 357 | 87.3 | 85.6 | 526 | 1,870 | 773 | 4,277 | 6,704 | 58.6 | 820 |
| Sidebar, c=16 | 589 | 1,020 | 328 | 319 | 1,813 | 5,802 | 947 | 12,489 | 22,302 | 217 | 2,701 |
| Sidebar, c=64 | 586 | 1,000 | 326 | 316 | 1,510 | 6,200 | 925 | 12,158 | 22,707 | 217 | 2,893 |
| Search, c=1 | 215 | 149 | 57.6 | 55.7 | 145 | 476 | 613 | 1,809 | 5,938 | 82.3 | 430 |
| Search, c=16 | 456 | 462 | 219 | 211 | 526 | 1,530 | 778 | 6,836 | 23,561 | 300 | 1,495 |
| Search, c=64 | 460 | 469 | 219 | 211 | 485 | 1,531 | 791 | 6,575 | 28,668 | 301 | 1,529 |
| Post a message, c=1 | 158 | 117 | 11.4 | 62.6 | 242 | 847 | 287 | 2,397 | 2,803 | 70.2 | 673 |
| Post a message, c=16 | 274 | 228 | 39.3 | 226 | 599 | 1,682 | 533 | 4,545 | 6,703 | 262 | 1,652 |
| Post a message, c=64 | 280 | 174 | 24.7 | 225 | 610 | 1,860 | 583 | 5,100 | 7,189 | 261 | 1,668 |
| /up, c=1 | 1,902 | 767 | 331 | 312 | 1,334 | 6,687 | 3,230 | 13,669 | 14,611 | 187 | 3,283 |
| /up, c=16 | 4,149 | 2,180 | 1,202 | 1,145 | 4,561 | 27,117 | 6,677 | 73,239 | 93,307 | 712 | 11,131 |
| /up, c=64 | 4,987 | 2,199 | 1,066 | 1,029 | 4,777 | 26,883 | 7,725 | 81,566 | 97,473 | 712 | 11,558 |

×Rails at 16 clients (>1: more requests/sec than Rails):

| Route | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Room page | 0.98× | 0.49× | 0.47× | 0.71× | 2.42× | 1.81× | 16.43× | 88.85× | 0.69× | 2.81× |
| Messages page | 0.66× | 0.32× | 0.31× | 0.50× | 1.82× | 1.63× | 11.93× | 54.48× | 0.80× | 3.69× |
| Sidebar | 1.73× | 0.56× | 0.54× | 3.08× | 9.85× | 1.61× | 21.20× | 37.85× | 0.37× | 4.58× |
| Search | 1.01× | 0.48× | 0.46× | 1.15× | 3.35× | 1.71× | 14.99× | 51.66× | 0.66× | 3.28× |
| Post a message | 0.83× | 0.14× | 0.82× | 2.18× | 6.13× | 1.94× | 16.56× | 24.42× | 0.95× | 6.02× |
| /up | 0.53× | 0.29× | 0.28× | 1.10× | 6.54× | 1.61× | 17.65× | 22.49× | 0.17× | 2.68× |

### HTTP latency (11 apps)

Median latency (p50, ms):

| Route, clients | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Room page, c=1 | 8.74 | 12.2 | 29.5 | 30.5 | 21.4 | 4.54 | 3.08 | 0.86 | 0.14 | 20.5 | 4.70 |
| Room page, c=16 | 59.2 | 59.6 | 129 | 133 | 91.9 | 24.9 | 34.8 | 2.81 | 0.61 | 91.6 | 22.2 |
| Room page, c=64 | 266 | 242 | 518 | 532 | 303 | 98.2 | 123 | 13.8 | 2.62 | 366 | 88.6 |
| Messages page, c=1 | 4.60 | 10.3 | 26.1 | 27.2 | 16.5 | 3.50 | 2.46 | 0.71 | 0.14 | 10.1 | 1.84 |
| Messages page, c=16 | 34.7 | 50.5 | 113 | 117 | 72.1 | 19.4 | 22.2 | 2.23 | 0.58 | 45.7 | 9.45 |
| Messages page, c=64 | 153 | 167 | 451 | 467 | 288 | 75.8 | 88.8 | 9.48 | 2.45 | 183 | 36.2 |
| Sidebar, c=1 | 3.52 | 2.79 | 11.4 | 11.6 | 1.87 | 0.49 | 1.23 | 0.20 | 0.15 | 16.9 | 1.17 |
| Sidebar, c=16 | 26.2 | 14.9 | 48.6 | 50.0 | 8.41 | 2.37 | 16.4 | 1.03 | 0.62 | 73.6 | 5.38 |
| Sidebar, c=64 | 110 | 58.5 | 196 | 202 | 34.5 | 9.08 | 69.1 | 4.74 | 2.63 | 294 | 21.7 |
| Search, c=1 | 4.57 | 6.32 | 17.3 | 17.8 | 6.78 | 1.99 | 1.58 | 0.48 | 0.17 | 12.1 | 2.29 |
| Search, c=16 | 33.1 | 30.2 | 73.0 | 75.5 | 30.2 | 9.67 | 20.5 | 1.68 | 0.63 | 53.1 | 10.4 |
| Search, c=64 | 143 | 37.0 | 293 | 302 | 120 | 39.9 | 80.7 | 8.84 | 2.07 | 212 | 41.4 |
| Post a message, c=1 | 5.88 | 7.00 | 26.2 | 15.4 | 3.64 | 1.08 | 3.50 | 0.38 | 0.36 | 14.2 | 1.36 |
| Post a message, c=16 | 47.0 | 32.0 | 134 | 67.4 | 20.5 | 8.02 | 29.6 | 2.63 | 2.27 | 61.0 | 7.98 |
| Post a message, c=64 | 227 | 68.1 | 1,783 | 280 | 99.2 | 30.8 | 110 | 8.74 | 8.85 | 245 | 37.0 |
| /up, c=1 | 0.46 | 1.29 | 3.02 | 3.17 | 0.67 | 0.14 | 0.27 | 0.07 | 0.07 | 5.37 | 0.29 |
| /up, c=16 | 3.48 | 7.00 | 13.1 | 13.7 | 3.16 | 0.59 | 2.03 | 0.13 | 0.15 | 22.4 | 1.33 |
| /up, c=64 | 12.0 | 15.8 | 52.4 | 54.4 | 13.0 | 2.11 | 7.73 | 0.38 | 0.59 | 89.5 | 5.27 |

99th percentile (p99, ms):

| Route, clients | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Room page, c=1 | 11.1 | 18.4 | 32.7 | 33.1 | 22.1 | 8.26 | 5.00 | 4.62 | 0.17 | 21.4 | 5.26 |
| Room page, c=16 | 128 | 145 | 170 | 192 | 135 | 46.0 | 47.3 | 12.6 | 2.08 | 124 | 36.1 |
| Room page, c=64 | 347 | 564 | 587 | 591 | 332 | 153 | 151 | 55.3 | 6.27 | 398 | 103 |
| Messages page, c=1 | 6.12 | 16.9 | 27.6 | 29.3 | 17.2 | 6.81 | 4.08 | 3.25 | 0.17 | 10.9 | 2.03 |
| Messages page, c=16 | 87.6 | 135 | 148 | 165 | 101 | 37.5 | 34.3 | 9.77 | 1.97 | 60.6 | 21.2 |
| Messages page, c=64 | 218 | 532 | 484 | 516 | 316 | 124 | 120 | 51.6 | 5.62 | 198 | 59.3 |
| Sidebar, c=1 | 4.89 | 3.17 | 12.4 | 12.5 | 2.12 | 1.16 | 2.87 | 0.73 | 0.18 | 18.0 | 1.40 |
| Sidebar, c=16 | 55.9 | 30.8 | 56.9 | 69.0 | 18.3 | 11.3 | 26.4 | 5.39 | 2.08 | 102 | 14.0 |
| Sidebar, c=64 | 212 | 149 | 209 | 220 | 48.7 | 22.8 | 91.9 | 13.1 | 6.43 | 324 | 37.1 |
| Search, c=1 | 6.17 | 11.4 | 19.9 | 20.2 | 8.37 | 7.23 | 3.86 | 3.75 | 0.21 | 12.8 | 2.57 |
| Search, c=16 | 78.1 | 81.2 | 100 | 104 | 44.2 | 23.4 | 29.7 | 9.77 | 1.45 | 69.8 | 21.1 |
| Search, c=64 | 196 | 520 | 317 | 330 | 803 | 71.4 | 99.6 | 27.5 | 4.62 | 231 | 55.8 |
| Post a message, c=1 | 15.7 | 23.3 | 1,716 | 28.2 | 17.0 | 5.01 | 6.42 | 0.94 | 0.44 | 15.3 | 5.57 |
| Post a message, c=16 | 165 | 773 | 2,599 | 157 | 131 | 31.6 | 44.2 | 13.9 | 5.58 | 79.9 | 42.8 |
| Post a message, c=64 | 333 | 3,578 | 5,153 | 368 | 214 | 73.2 | 137 | 57.3 | 15.7 | 264 | 72.2 |
| /up, c=1 | 2.14 | 1.56 | 3.31 | 3.50 | 1.05 | 0.17 | 1.75 | 0.09 | 0.09 | 5.55 | 0.39 |
| /up, c=16 | 10.6 | 15.4 | 20.8 | 24.2 | 8.61 | 1.79 | 8.29 | 1.96 | 0.60 | 34.3 | 4.03 |
| /up, c=64 | 29.1 | 94.1 | 67.0 | 73.1 | 23.5 | 10.5 | 19.9 | 5.89 | 1.97 | 99.8 | 13.6 |

### What the pages contain

The work behind a request is only comparable between apps that render the same page. One untimed
GET per repetition:

| Page | Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Room page | elements | 4,000 | 4,000 | 4,027 | 4,027 | 4,027 | 4,000 | 4,000 | 3,675 | 3,675 | 4,000 | 4,000 |
| Room page | decoded KB | 453 | 410 | 390 | 390 | 390 | 410 | 453 | 365 | 406 | 452 | 452 |
| Room page | gzip KB | 43.3 | 20.1 | 28.3 | 19.6 | 19.6 | 19.9 | 20.7 | 20.8 | 23.7 | 44.0 | 43.9 |
| Room page | CSRF inputs (distinct values) | 323 (323) | 323 (1) | 644 (1) | 644 (1) | 644 (1) | 323 (1) | 323 (2) | 0 (0) | 0 (0) | 323 (323) | 323 (323) |
| Messages page | elements | 3,720 | 3,400 | 3,880 | 3,880 | 3,880 | 3,400 | 3,720 | 3,400 | 3,400 | 3,720 | 3,720 |
| Messages page | decoded KB | 421 | 333 | 371 | 371 | 371 | 333 | 421 | 334 | 375 | 421 | 421 |
| Messages page | gzip KB | 34.0 | 10.9 | 19.2 | 13.0 | 13.0 | 10.7 | 11.8 | 12.5 | 15.8 | 35.1 | 35.1 |
| Messages page | CSRF inputs (distinct values) | 320 (320) | 0 (0) | 640 (1) | 640 (1) | 640 (1) | 0 (0) | 320 (1) | 0 (0) | 0 (0) | 320 (320) | 320 (320) |
| Search | elements | 1,420 | 1,420 | 1,338 | 1,338 | 1,338 | 1,420 | 1,420 | 1,311 | 1,311 | 1,420 | 1,420 |
| Search | decoded KB | 162 | 147 | 133 | 133 | 133 | 147 | 162 | 132 | 146 | 161 | 161 |
| Search | gzip KB | 17.1 | 9.1 | 10.9 | 8.0 | 8.0 | 9.1 | 10.0 | 9.2 | 9.5 | 17.3 | 17.3 |
| Search | CSRF inputs (distinct values) | 107 (107) | 107 (1) | 208 (1) | 208 (1) | 208 (1) | 107 (1) | 107 (5) | 0 (0) | 0 (0) | 107 (107) | 107 (107) |
| Sidebar | elements | 248 | 63 | 22 | 22 | 22 | 63 | 248 | 84 | 243 | 248 | 248 |
| Sidebar | decoded KB | 31 | 7 | 3 | 3 | 3 | 7 | 31 | 9 | 30 | 31 | 31 |
| Sidebar | gzip KB | 6.1 | 1.9 | 0.8 | 0.8 | 0.8 | 1.9 | 6.0 | 2.2 | 5.8 | 6.2 | 6.2 |
| Sidebar | CSRF inputs (distinct values) | 3 (3) | 0 (0) | 0 (0) | 0 (0) | 0 (0) | 0 (0) | 3 (1) | 0 (0) | 0 (0) | 3 (3) | 3 (3) |

- **Room, messages and search pages** show the same 40 (13 for search) messages in the same order
  in every app, with element counts within 10% of Rails'.
- **The sidebar is not the same page everywhere.** Rails, Elixir, Rust and Symfony render about
  245 elements (31 KB); Django and Express 63, Go 84, the Laravel port 22 (3 KB). Sidebar numbers
  are not like-for-like.
- **Wire size depends on the CSRF token as much as on the markup.** Rails and Symfony mask the
  token per form: a room page has 323 hidden inputs with 323 distinct, incompressible values, so it
  is about 44 KB gzipped. Laravel, Django and Express repeat one token, and their room page is
  20–28 KB gzipped for HTML of similar size. Go and Rust render no token.

### Laravel vs Symfony on the same runtime

Both on FrankenPHP with PHP 8.4, the same thread counts and the same SQLite 3.53.4. Classic = no
worker (the framework boots on every request); worker = booted once per thread (Laravel Octane,
Symfony Runtime). Medians of 5 repetitions; HTTP at 16 clients. S/L above 1× means Symfony does
better (more requests or messages per second; fewer ms, bytes or MB).

| Metric | Laravel FrankenPHP classic | Symfony classic | S/L classic | Laravel Octane | Symfony | S/L worker |
|---|---:|---:|---:|---:|---:|---:|
| Room page req/s | 120 | 174 | 1.45× | 178 | 711 | 4.01× |
| Messages page req/s | 137 | 350 | 2.56× | 222 | 1,627 | 7.33× |
| Sidebar req/s | 318 | 217 | 0.68× | 1,698 | 2,674 | 1.57× |
| Search req/s | 211 | 301 | 1.43× | 528 | 1,499 | 2.84× |
| Post a message req/s | 225 | 261 | 1.16× | 599 | 1,655 | 2.76× |
| /up req/s | 1,126 | 709 | 0.63× | 4,612 | 11,033 | 2.39× |
| Room page p50 ms | 133 | 91.6 | 1.45× | 92.6 | 22.2 | 4.17× |
| Messages page p50 ms | 117 | 45.6 | 2.56× | 71.7 | 9.42 | 7.61× |
| Sidebar p50 ms | 50.2 | 73.6 | 0.68× | 8.30 | 5.46 | 1.52× |
| Search p50 ms | 75.6 | 53.0 | 1.43× | 30.1 | 10.4 | 2.89× |
| Post a message p50 ms | 68.3 | 61.0 | 1.12× | 20.6 | 7.92 | 2.60× |
| Room page avg response bytes (gzip) | 20,032 | 45,042 | 0.44× | 20,031 | 45,044 | 0.44× |
| Messages page avg response bytes (gzip) | 13,333 | 35,935 | 0.37× | 13,315 | 35,921 | 0.37× |
| Sidebar avg response bytes (gzip) | 817 | 6,395 | 0.13× | 817 | 6,395 | 0.13× |
| Search avg response bytes (gzip) | 8,215 | 17,716 | 0.46× | 8,215 | 17,716 | 0.46× |
| Post a message avg response bytes (gzip) | 1,973 | 2,031 | 0.97× | 1,975 | 2,033 | 0.97× |
| Cable 1000 clients: delivered msgs/s | 5.00 | 108 | 21.70× | 5.00 | 171 | 34.14× |
| Cable 1000 clients: paced post→all p50 ms | 321 | 62.1 | 5.17× | 318 | 41.5 | 7.68× |
| Upload: POST → first <img> (ms) | 77.5 | 63.2 | 1.23× | 58.8 | 35.7 | 1.65× |
| Idle memory.current (MB) | 168 | 192 | 0.88× | 256 | 230 | 1.11× |
| Peak memory.current (MB) | 508 | 780 | 0.65× | 751 | 870 | 0.86× |
| Cold start (ms) | 951 | 925 | 1.03× | 902 | 752 | 1.20× |

Page equivalence, from the same report: room, messages and search pages are **equivalent** (same
message window; elements 4,027 / 4,000, 3,880 / 3,720 and 1,338 / 1,420 for Laravel / Symfony). The
sidebar is **different** (22 / 248 elements), so its ratio is not like-for-like. Laravel's smaller
responses come from its single repeated CSRF token, not from smaller HTML (room page 390 / 452 KB
decoded).

- In worker mode, Symfony serves 2.8–7.3× Laravel Octane's requests/sec on the equivalent pages and
  on posting, and delivers 34× more messages/s to 1,000 cable clients.
- In classic mode, Symfony is 1.2–2.6× faster on the equivalent pages and on posting. Laravel is
  faster on the sidebar (a much smaller page) and on `/up`, which does little beyond booting the
  framework.
- Laravel uses less memory at peak (0.65× and 0.86×). Idle `memory.current` varies widely between
  repetitions (Laravel Octane 196–267 MB, Laravel classic 158–224 MB); idle anonymous memory is
  stable and lower for Symfony (64 and 67 MB against 83 and 118 MB).

Requests/sec and p99 at each concurrency (median [min–max]):

| Route, clients, metric | Laravel classic | Laravel Octane | Symfony classic | Symfony |
|---|---:|---:|---:|---:|
| Room page, c=1, req/s | 32.8 [32.6–33.2] | 46.7 [46.3–47.1] | 48.8 [48.1–48.9] | 209 [206–210] |
| Room page, c=1, p99 ms | 32.3 [31.4–32.9] | 22.6 [22.2–25.6] | 21.6 [21.2–24.7] | 5.20 [5.11–5.33] |
| Room page, c=16, req/s | 120 [119–120] | 178 [172–180] | 174 [164–174] | 711 [710–714] |
| Room page, c=16, p99 ms | 190 [171–196] | 127 [127–130] | 127 [123–223] | 36.6 [34.1–38.0] |
| Room page, c=64, req/s | 119 [119–120] | 210 [210–210] | 174 [168–174] | 717 [717–719] |
| Room page, c=64, p99 ms | 589 [583–591] | 333 [330–339] | 403 [389–415] | 103 [102–104] |
| Messages page, c=1, req/s | 36.8 [36.4–36.9] | 60.4 [60.0–60.7] | 97.9 [92.3–99.2] | 537 [532–542] |
| Messages page, c=1, p99 ms | 28.9 [28.1–30.2] | 19.0 [17.0–21.7] | 10.9 [10.6–16.7] | 2.06 [2.03–2.08] |
| Messages page, c=16, req/s | 137 [136–137] | 222 [221–222] | 350 [348–351] | 1,627 [1,606–1,656] |
| Messages page, c=16, p99 ms | 161 [158–169] | 102 [99–103] | 62.7 [60.2–64.1] | 21.2 [20.9–21.6] |
| Messages page, c=64, req/s | 136 [135–137] | 221 [221–222] | 350 [349–350] | 1,731 [1,721–1,739] |
| Messages page, c=64, p99 ms | 517 [502–535] | 318 [317–321] | 198 [197–200] | 59.2 [57.9–59.5] |
| Sidebar, c=1, req/s | 86.1 [85.1–88.0] | 519 [514–526] | 58.7 [57.4–59.1] | 811 [790–824] |
| Sidebar, c=1, p99 ms | 12.3 [11.9–13.1] | 2.15 [2.13–2.20] | 18.0 [17.9–18.8] | 1.40 [1.35–1.41] |
| Sidebar, c=16, req/s | 318 [316–318] | 1,698 [1,612–1,871] | 217 [216–217] | 2,674 [2,662–2,683] |
| Sidebar, c=16, p99 ms | 71.8 [69.3–72.5] | 18.2 [16.7–18.7] | 98.9 [97.0–105.0] | 14.5 [13.8–14.6] |
| Sidebar, c=64, req/s | 317 [305–318] | 1,832 [1,815–1,838] | 217 [217–217] | 2,869 [2,853–2,890] |
| Sidebar, c=64, p99 ms | 221 [218–531] | 45.9 [45.0–47.2] | 319 [316–323] | 37.5 [37.2–39.2] |
| Search, c=1, req/s | 56.7 [55.9–57.0] | 144 [135–146] | 82.2 [80.8–84.2] | 426 [423–436] |
| Search, c=1, p99 ms | 20.0 [18.3–20.9] | 8.26 [7.65–8.35] | 12.9 [12.4–13.6] | 2.59 [2.50–2.72] |
| Search, c=16, req/s | 211 [210–211] | 528 [510–529] | 301 [301–302] | 1,499 [1,494–1,510] |
| Search, c=16, p99 ms | 103 [100–106] | 44.7 [44.2–48.9] | 74.6 [72.3–76.8] | 20.6 [20.1–21.1] |
| Search, c=64, req/s | 211 [209–212] | 531 [530–536] | 301 [300–301] | 1,523 [1,517–1,530] |
| Search, c=64, p99 ms | 330 [327–394] | 134 [133–134] | 233 [230–236] | 55.0 [54.4–57.3] |
| Post a message, c=1, req/s | 63.0 [62.7–63.4] | 237 [233–242] | 70.3 [69.9–72.4] | 668 [668–678] |
| Post a message, c=1, p99 ms | 28.7 [26.6–29.2] | 17.1 [16.7–18.2] | 15.3 [14.8–16.2] | 5.79 [5.59–6.12] |
| Post a message, c=16, req/s | 225 [221–229] | 599 [585–611] | 261 [261–262] | 1,655 [1,652–1,673] |
| Post a message, c=16, p99 ms | 153 [132–165] | 128 [118–129] | 80.8 [79.2–81.9] | 41.5 [39.9–44.0] |
| Post a message, c=64, req/s | 225 [223–229] | 617 [594–624] | 261 [260–262] | 1,662 [1,630–1,672] |
| Post a message, c=64, p99 ms | 365 [359–368] | 210 [206–230] | 265 [261–266] | 71.4 [70.0–72.9] |
| /up, c=1, req/s | 324 [314–329] | 1,439 [1,415–1,446] | 186 [185–193] | 3,253 [3,226–3,391] |
| /up, c=1, p99 ms | 3.42 [3.35–3.60] | 0.89 [0.86–0.91] | 5.60 [5.55–5.85] | 0.39 [0.38–0.40] |
| /up, c=16, req/s | 1,126 [1,058–1,155] | 4,612 [4,582–4,660] | 709 [708–712] | 11,033 [10,976–11,140] |
| /up, c=16, p99 ms | 24.4 [23.4–27.0] | 8.59 [8.49–8.80] | 34.0 [32.4–36.2] | 4.05 [4.04–4.22] |
| /up, c=64, req/s | 1,165 [1,157–1,174] | 4,738 [2,603–4,840] | 712 [711–713] | 11,444 [11,369–11,555] |
| /up, c=64, p99 ms | 65.5 [64.1–67.8] | 24.0 [23.6–24.1] | 99.9 [98.9–100.8] | 13.8 [13.8–14.0] |

Cable, upload, startup and memory (median [min–max]):

| Metric | Laravel classic | Laravel Octane | Symfony classic | Symfony |
|---|---:|---:|---:|---:|
| 100 clients: subscribed | 100 [100–100] | 100 [100–100] | 100 [100–100] | 100 [100–100] |
| 100 clients: connect+subscribe all (s) | 0.30 [0.25–0.37] | 0.28 [0.17–0.30] | 0.07 [0.07–0.07] | 0.07 [0.06–0.07] |
| 100 clients: paced messages delivered to all | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] |
| 100 clients: paced post→one client p50 ms | 33.3 [31.9–37.1] | 22.1 [21.7–25.5] | 46.2 [45.4–46.7] | 16.3 [15.8–16.6] |
| 100 clients: paced post→all clients p50 ms | 37.6 [35.8–40.4] | 25.9 [23.5–29.7] | 46.7 [46.2–47.2] | 17.7 [17.5–18.4] |
| 100 clients: paced post→all clients p99 ms | 55.2 [47.3–60.3] | 41.8 [39.2–46.0] | 50.7 [50.2–51.8] | 21.1 [19.7–26.0] |
| 100 clients: max sustained msgs/s (delivered to all) | 48.9 [48.8–49.0] | 51.6 [51.2–52.2] | 224 [223–225] | 973 [969–984] |
| 100 clients: deliveries/s (client×message) | 4,888 [4,875–4,896] | 5,165 [5,117–5,221] | 22,372 [22,277–22,527] | 97,280 [96,874–98,413] |
| 100 clients: saturated post→all p50 ms | 18,989 [18,383–19,153] | 35,062 [34,996–35,127] | 18.3 [18.2–18.3] | 5.20 [5.12–5.20] |
| 100 clients: saturated POST p50 ms | 24.7 [24.5–24.9] | 6.34 [6.29–6.40] | 16.9 [16.9–17.0] | 3.42 [3.39–3.45] |
| 1000 clients: subscribed | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] |
| 1000 clients: connect+subscribe all (s) | 16.6 [16.6–16.7] | 16.8 [16.7–16.8] | 0.21 [0.21–0.25] | 0.20 [0.20–0.24] |
| 1000 clients: paced messages delivered to all | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] |
| 1000 clients: paced post→one client p50 ms | 281 [233–289] | 275 [256–296] | 56.9 [54.8–58.4] | 34.4 [33.6–35.3] |
| 1000 clients: paced post→all clients p50 ms | 321 [273–332] | 318 [297–330] | 62.1 [59.3–62.7] | 41.5 [40.1–42.6] |
| 1000 clients: paced post→all clients p99 ms | 368 [325–436] | 415 [355–492] | 69.2 [67.2–71.2] | 51.3 [48.1–58.3] |
| 1000 clients: max sustained msgs/s (delivered to all) | 5.00 [4.90–5.00] | 5.00 [5.00–5.00] | 108 [107–109] | 171 [169–171] |
| 1000 clients: deliveries/s (client×message) | 4,962 [4,934–5,000] | 5,019 [4,979–5,043] | 108,484 [107,328–109,001] | 170,689 [169,389–171,045] |
| 1000 clients: saturated post→all p50 ms | 38,142 [37,880–38,240] | 38,306 [38,076–38,404] | 1,501 [1,208–1,577] | 4,690 [3,785–5,640] |
| 1000 clients: saturated POST p50 ms | 24.9 [24.0–25.7] | 6.41 [6.19–6.52] | 31.7 [30.9–32.0] | 2.15 [1.92–2.37] |

| Metric | Laravel classic | Laravel Octane | Symfony classic | Symfony |
|---|---:|---:|---:|---:|
| POST with attachment (ms) | 69.9 [69.0–70.4] | 57.3 [56.2–58.9] | 49.7 [47.9–51.0] | 34.8 [33.9–36.3] |
| then GET first <img> → 200 (ms) | 7.90 [7.70–8.00] | 1.50 [1.40–1.60] | 13.3 [12.5–13.4] | 1.00 [1.00–1.10] |
| POST → first <img> served (ms) | 77.5 [77.1–78.7] | 58.8 [57.6–60.4] | 63.2 [60.8–65.2] | 35.7 [34.8–37.3] |
| thumbnail check: POST → thumbnail bytes (ms) | 79.5 [78.7–82.3] | 62.0 [60.0–63.1] | 62.8 [61.4–64.2] | 39.7 [37.5–43.9] |

| Metric | Laravel classic | Laravel Octane | Symfony classic | Symfony |
|---|---:|---:|---:|---:|
| cold start: docker run → /up 200 (ms) | 951 [822–991] | 902 [879–954] | 925 [751–934] | 752 [707–854] |
| idle memory.current (MB) | 168 [158–224] | 256 [196–267] | 192 [189–227] | 230 [222–231] |
| idle anon (MB) | 83.0 [83.0–83.0] | 118 [118–120] | 64.0 [64.0–64.0] | 67.0 [67.0–68.0] |
| peak memory.current under load (MB) | 508 [493–522] | 751 [730–798] | 780 [760–804] | 870 [849–890] |
| peak anon under load (MB) | 315 [311–345] | 488 [461–510] | 468 [455–495] | 525 [494–531] |

### Action Cable fan-out (11 apps)

One room; each client subscribes as chatter.js does. Medians of 3 repetitions. "Max sustained
msgs/s (delivered to all)" counts only messages that reached every client, so for an app that
fails the saturated phase it is lower than what was posted.

| Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Saturated phase, 100 clients: every message to every client | pass | **fail** (3/3) | pass | pass | **fail** (3/3) | **fail** (1/3) | pass | pass | pass | pass | pass |
| Saturated phase, 1,000 clients: every message to every client | pass | **fail** (3/3) | **fail** (3/3) | **fail** (3/3) | **fail** (3/3) | pass | pass | pass | pass | pass | pass |
| 100 clients: subscribed | 100 | 100 | 100 | 100 | 100 | 100 | 100 | 100 | 100 | 100 | 100 |
| 100 clients: connect+subscribe all (s) | 0.27 | 0.26 | 0.34 | 0.30 | 0.19 | 0.47 | 0.06 | 0.06 | 0.07 | 0.07 | 0.07 |
| 100 clients: paced messages delivered to all | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 |
| 100 clients: paced post→one client p50 ms | 37.7 | 33.2 | 101 | 34.8 | 23.3 | 14.5 | 12.6 | 7.72 | 5.99 | 45.8 | 16.2 |
| 100 clients: paced post→all clients p50 ms | 47.3 | 53.2 | 101 | 37.8 | 26.8 | 21.3 | 14.3 | 9.68 | 7.32 | 46.3 | 17.6 |
| 100 clients: paced post→all clients p99 ms | 76.4 | 79.7 | 1,370 | 53.9 | 40.3 | 33.0 | 18.6 | 12.4 | 12.7 | 51.5 | 21.7 |
| 100 clients: max sustained msgs/s (delivered to all) | 84.9 | 0.40 | 8.90 | 49.0 | 51.7 | 181 | 316 | 2,060 | 3,215 | 224 | 981 |
| 100 clients: deliveries/s (client×message) | 8,493 | 67.0 | 891 | 4,899 | 5,170 | 18,140 | 31,639 | 205,977 | 321,506 | 22,448 | 98,140 |
| 100 clients: saturated post→all p50 ms | 61.6 | 225 | 191 | 18,842 | 34,996 | 3,820 | 10.4 | 3.38 | 1.55 | 18.1 | 5.20 |
| 100 clients: saturated POST p50 ms | 43.6 | 11.1 | 63.8 | 24.1 | 6.29 | 3.74 | 12.0 | 1.59 | 1.10 | 17.0 | 3.40 |
| 1000 clients: subscribed | 1,000 | 1,000 | 1,000 | 1,000 | 1,000 | 1,000 | 1,000 | 1,000 | 1,000 | 1,000 | 1,000 |
| 1000 clients: connect+subscribe all (s) | 1.32 | 2.75 | 20.1 | 16.7 | 16.6 | 1.47 | 0.44 | 0.16 | 0.11 | 0.23 | 0.21 |
| 1000 clients: paced messages delivered to all | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 | 30.0 |
| 1000 clients: paced post→one client p50 ms | 49.2 | 2,599 | 462 | 286 | 262 | 27.4 | 20.4 | 19.6 | 15.2 | 55.8 | 34.2 |
| 1000 clients: paced post→all clients p50 ms | 98.1 | 3,453 | 499 | 319 | 303 | 46.3 | 28.1 | 27.2 | 23.0 | 59.6 | 41.0 |
| 1000 clients: paced post→all clients p99 ms | 151 | 6,730 | 805 | 383 | 360 | 75.7 | 38.8 | 38.6 | 28.9 | 69.0 | 51.0 |
| 1000 clients: max sustained msgs/s (delivered to all) | 11.7 | 0.10 | 4.40 | 5.00 | 5.00 | 23.2 | 74.3 | 304 | 570 | 110 | 172 |
| 1000 clients: deliveries/s (client×message) | 11,744 | 121 | 4,450 | 4,957 | 5,030 | 23,152 | 74,310 | 304,496 | 569,602 | 109,512 | 171,632 |
| 1000 clients: saturated post→all p50 ms | 1,524 | 519 | 33,128 | 37,880 | 38,437 | 962 | 47.6 | 24.5 | 18.3 | 1,098 | 4,223 |
| 1000 clients: saturated POST p50 ms | 147 | 11.2 | 31.3 | 24.8 | 6.40 | 61.4 | 53.8 | 11.6 | 6.45 | 32.2 | 2.65 |

### Upload (11 apps)

| Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| POST with attachment (ms) | 80.1 | 63.1 | 116 | 71.3 | 56.2 | 33.2 | 91.9 | 31.4 | 35.5 | 49.5 | 34.3 |
| then GET first <img> → 200 (ms) | 0.30 | 2.40 | 7.80 | 7.90 | 1.50 | 1.10 | 0.30 | 0.20 | 0.20 | 13.3 | 1.00 |
| POST → first <img> served (ms) | 80.4 | 65.4 | 123 | 79.3 | 57.5 | 34.4 | 92.2 | 31.7 | 35.6 | 62.5 | 35.7 |
| thumbnail check: POST → thumbnail bytes (ms) | 69.8 | 101 | 123 | 83.7 | 60.9 | 34.0 | 94.8 | 33.8 | 35.0 | 64.8 | 40.0 |
| thumbnail check: thumbnail size (bytes) | 84,296 | 84,296 | 75,349 | 75,289 | 75,289 | 93,976 | 84,296 | 84,296 | 84,296 | 84,296 | 84,296 |

The ports produce different thumbnail bytes (75–94 KB); in every app the thumbnail is smaller
than the original and is served.

### Startup and memory (11 apps)

Medians of 3 repetitions. `memory.current` is the container's cgroup; the Django Redis sidecar
(25–29 MB) is outside it.

| Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| cold start: docker run → /up 200 (ms) | 2,402 | 858 | 820 | 903 | 911 | 777 | 798 | 431 | 403 | 772 | 775 |
| idle memory.current (MB) | 438 | 289 | 156 | 227 | 211 | 271 | 189 | 48.0 | 65.0 | 227 | 229 |
| idle anon (MB) | 284 | 223 | 70.0 | 83.0 | 121 | 162 | 119 | 9.00 | 15.0 | 64.0 | 67.0 |
| peak memory.current under load (MB) | 1,527 | 1,169 | 327 | 540 | 818 | 2,805 | 708 | 483 | 452 | 802 | 860 |
| peak anon under load (MB) | 1,478 | 1,060 | 182 | 357 | 507 | 2,698 | 567 | 378 | 302 | 496 | 507 |
| 1000 clients, saturated fan-out: whole container Pss | 1,327 | 870 | 181 | 362 | 520 | 2,649 | 595 | 200 | 120 | 458 | 582 |

Idle memory varies between repetitions for some apps (Laravel FrankenPHP classic 166–228 MB,
Laravel Octane 179–269 MB); the reports give the ranges. Anonymous memory is the steadier
measure.

## Limitations

- **vCPUs are not cores.** `--cpuset-cpus 0-3` pins four vCPU threads of the VM. macOS decides
  which physical P or E cores run them, and the mix can change from moment to moment. Absolute
  numbers depend on that scheduling. Compare the ratios between apps, which all ran under the
  same conditions.
- **Not comparable with the published table.** The once-campfire README numbers used 4 hardware
  threads of an AMD Ryzen AI MAX+ 395 on Linux. The harness, seed and load generator are the same;
  the hardware and the virtualisation are not.
- **Memory-backed filesystem.** The apps' copies of the seed are on OrbStack's VM root overlay,
  whose upper layer is tmpfs. `fsync` is nearly free, so SQLite commits cost less than on a disk.
  This applies to all apps equally, but it flatters write-heavy routes. On a disk, the published
  Laravel image's `synchronous=FULL` would likely cost it more.
- **Pages differ.** The sidebar is not the same page in every port (see above). Wire sizes differ
  with the CSRF scheme.
- **Port limitations in the cable suite.** Django and the three Laravel images fail the saturated
  fan-out in every repetition, and Express failed it once. Their saturated cable numbers are what
  reached every client, not what was posted.
- The **Django Redis sidecar** is outside the app's container cgroup. Its CPU counts against the
  same pinned CPUs, but its memory is reported on separate rows.
- The **published Laravel image** has a fixed process model whatever the CPU count, and is used as
  shipped.
- **The Laravel FrankenPHP and Symfony images carry no revision label of their own.** Both were
  built from working trees on 2026-10-06; `env.txt` records their image ids
  (`sha256:8f2db872…` and `sha256:aeabf684…`).
- Every app runs as root inside the runner rather than as its image's own user. This doesn't
  change any measurement.
- **Upload** timing measures POST → avatar, not POST → thumbnail (see above).
- The writes check covers the post_message route; the cable posters and uploads that follow are
  validated by delivery and by the thumbnail, not by a database count.

## Reproducing

Prerequisites: Docker (OrbStack on macOS), no other containers running, about 3.5 hours for the
11-app run with 3 repetitions and a little over 2 hours for the 4-app run with 5 (as measured on
2026-10-06).

```sh
bench/bin/runner bench/bin/setup         # once: Rust harness, seed, load generator, Rails images
bench/bin/runner bench/bin/build-ports   # once: Laravel, Django, Express, Elixir, Go and Rust images
docker build -t campfire-symfony:app .
(cd ../campfire-laravel-frankenphp && docker build -t once-campfire-laravel-frankenphp:bench .)

# All 11 apps
caffeinate -dims bench/bin/runner bench/run \
  --apps rails,django,laravel,laravel-frankenphp-classic,laravel-frankenphp,express,elixir,go,rust,symfony-classic,symfony \
  --reps 3 --out bench/results/$(date +%F)-m3pro-all

# Laravel and Symfony on the same FrankenPHP runtime
caffeinate -dims bench/bin/runner bench/run \
  --apps laravel-frankenphp-classic,laravel-frankenphp,symfony-classic,symfony \
  --reps 5 --out bench/results/$(date +%F)-m3pro-grid

bench/bin/runner bench/report bench/results/<dir>   # re-render report.md
```

- A run writes `bench/results/<dir>/`:
  - `env.txt`;
  - `<app>-<rep>.json` (raw results with a validation verdict, the writes check and the page
    contents);
  - `logs/`;
  - `run.log`, `uptime.log`;
  - `report.md`.
- `bench/README.md` lists the knobs (`SERVER_CPUS`, `HTTP_CONCS`, `CABLE_CLIENTS`, …), the
  smoke configuration and what an image must satisfy to be measured.
- `$BENCH_HOME` doesn't survive an OrbStack restart. Re-run `setup` and `build-ports` after one.
