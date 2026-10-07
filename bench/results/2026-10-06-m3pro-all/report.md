```
date: 2026-10-06T23:01:43+00:00
host: Apple M3 Pro (6P+6E cores), macOS 26.7.1; Docker VM: OrbStack, 12 vCPUs, 16819298304 B kernel 7.0.14-orbstack-00380-ga7e0a2dc9535
host power: AC Power, lowpowermode 0
server cpus: 0-3 (nproc 4); loadgen cpus: 4-7; harness cpus: 8-11; network: host; port: 4390
workloads: suites [http cable upload]; http 8s at c=[1 16 64] (2s warm-up at c=4); cable clients [100 1000], 15s saturation with 4 posters; upload reps 5
load wait: LOAD_MAX=1.5, LOAD_WAIT_SECS=900; write check settle: 10s; containers running before the run: 1
user agent: (none)
work dir: /opt/campfire-bench/work (overlay)
harness: campfire-symfony bench/run @ 7dcc719 (2 changed files in bench/); once-campfire-rust @ 64f8635 (loadgen, seed)
seed: 50a76ba5a2f63b93 production.sqlite3; loadgen: dbb998a3ba2cdf13
rails image: campfire-reference:app linux/arm64 sha256:28a60771be65155fb70f9e3c14b16be434dbef133e96bca19381f3f7015ecb3d revision 254dd1d46f67f2bace6c79d77f0ac026ba4c9226 source  created 2026-10-05T09:16:44.540398778+02:00 digests campfire-reference@sha256:28a60771be65155fb70f9e3c14b16be434dbef133e96bca19381f3f7015ecb3d
rails process model: Puma WEB_CONCURRENCY=3 workers x RAILS_MAX_THREADS=5, JOB_CONCURRENCY=3, resque-pool ceil(nproc*0.5)=2 workers, Thruster, in-container Redis on :6379; user 0:0
django image: once-campfire-django:bench linux/arm64 sha256:32be9db6826422d2c56e58026deba74a5a31c9f440ee878edd71f1b8266faeb6 revision 7cff970b9860c952883ba5a6ed4d624194307973 source https://github.com/basecamp/once-campfire-django created 2026-10-05T09:29:30.629705849+02:00 digests once-campfire-django@sha256:32be9db6826422d2c56e58026deba74a5a31c9f440ee878edd71f1b8266faeb6
django process model: Uvicorn WEB_WORKERS=4 processes (async; a job thread in each), Redis sidecar redis:7-alpine on 127.0.0.1:4392 pinned to 0-3; user 0:0
laravel image: once-campfire-laravel:bench linux/arm64 sha256:b90afeddd8c2f08e0e3df889c171dcdddf1f1235faa43873c588baea2fcceecf revision 608d465904366652319fd7bcd4c727393f1b115d source https://github.com/basecamp/once-campfire-laravel created 2026-10-05T09:30:07.02982869+02:00 digests once-campfire-laravel@sha256:b90afeddd8c2f08e0e3df889c171dcdddf1f1235faa43873c588baea2fcceecf
laravel process model: image defaults: nginx 2 workers, PHP-FPM pm=static 8 children, 1 queue:work, 1 Workerman cable process; CABLE_PORT=4391 FPM_PORT=4392; user 0:0
laravel-frankenphp-classic image: once-campfire-laravel-frankenphp:bench linux/arm64 sha256:8f2db872fec0e77c17b0eb585aca576ddfd79b3c5889e2555ad6bba53928453e revision none(label-inherited-from-FrankenPHP-233f793) source https://github.com/php/frankenphp created 2026-10-06T10:01:32.672343852+02:00 digests once-campfire-laravel-frankenphp@sha256:8f2db872fec0e77c17b0eb585aca576ddfd79b3c5889e2555ad6bba53928453e
laravel-frankenphp-classic process model: FrankenPHP classic mode (no worker), PHP_THREADS = 2×CPUs; bin/cable on 127.0.0.1:4391, queue:work; user 0:0
laravel-frankenphp image: once-campfire-laravel-frankenphp:bench linux/arm64 sha256:8f2db872fec0e77c17b0eb585aca576ddfd79b3c5889e2555ad6bba53928453e revision none(label-inherited-from-FrankenPHP-233f793) source https://github.com/php/frankenphp created 2026-10-06T10:01:32.672343852+02:00 digests once-campfire-laravel-frankenphp@sha256:8f2db872fec0e77c17b0eb585aca576ddfd79b3c5889e2555ad6bba53928453e
laravel-frankenphp process model: Octane + FrankenPHP worker mode, PHP_WORKERS = 2×CPUs; bin/cable on 127.0.0.1:4391, queue:work; user 0:0
express image: once-campfire-express:bench linux/arm64 sha256:45226fee5db058b943bb311079a69cbbbf19cbfc8007666de6846dd246d4ca48 revision 361330a0ecfb5abb74c8c67d243717282652aec5 source https://github.com/basecamp/once-campfire-express created 2026-10-06T09:14:28.804161029+02:00 digests once-campfire-express@sha256:45226fee5db058b943bb311079a69cbbbf19cbfc8007666de6846dd246d4ca48
express process model: Node cluster: primary (jobs, cable IPC) + WEB_WORKERS=3 HTTP/WebSocket workers on :4390; user 0:0
elixir image: once-campfire-elixir:bench linux/arm64 sha256:b1964eabffd7f7c69b55165cf705a1af35431f9e782c201dd0e2177119481bf4 revision f15fc9eb609286f1e3da970e7dfd95e3bf69f82f source https://github.com/basecamp/once-campfire-elixir created 2026-10-06T09:18:41.825638249+02:00 digests once-campfire-elixir@sha256:b1964eabffd7f7c69b55165cf705a1af35431f9e782c201dd0e2177119481bf4
elixir process model: bin/container-start: Thruster :4390 -> Bandit :4391 in one BEAM (schedulers = 4 CPUs, CAMPFIRE_WORKER=1 job worker), in-container Redis on :6379; Rails-style env WEB_CONCURRENCY=3 JOB_CONCURRENCY=3 RAILS_MAX_THREADS=5; user 0:0
go image: once-campfire-go:bench linux/arm64 sha256:ca8d0af681edec946969d951d15d0ae0b7716dd2e1f3f0305718be30d909e4b4 revision 8d2f7f24f56dac87dba0211b68b14d9e21e4b516 source https://github.com/basecamp/once-campfire-go created 2026-10-06T09:17:11.677373788+02:00 digests once-campfire-go@sha256:ca8d0af681edec946969d951d15d0ae0b7716dd2e1f3f0305718be30d909e4b4
go process model: one campfire process: front server :4390, app 127.0.0.1:4391, in-process jobs JOB_CONCURRENCY=3, GOMAXPROCS=4; user 0:0
rust image: once-campfire-rust:bench linux/arm64 sha256:ad5c4263480d9892dc2531faf480540381ed2bfc03b2f71b53feaac4e200160b revision ccece30e8e160d8c3e05bf395ee55ee35962093b source https://github.com/basecamp/once-campfire-rust created 2026-10-06T09:18:44.633579116+02:00 digests once-campfire-rust@sha256:ad5c4263480d9892dc2531faf480540381ed2bfc03b2f71b53feaac4e200160b
rust process model: one campfire process: front server :4390, app 127.0.0.1:4391, RAILS_MAX_THREADS=5 reader pool, JOB_CONCURRENCY=3; user 0:0
symfony-classic image: campfire-symfony:app linux/arm64 sha256:aeabf684ed8d7fe95489ad715059c1de31535ff3c6ba9b4dc5f708b73e03a56f revision none(label-inherited-from-FrankenPHP-233f793) source  created 2026-10-06T09:13:32.347124452+02:00 digests campfire-symfony@sha256:aeabf684ed8d7fe95489ad715059c1de31535ff3c6ba9b4dc5f708b73e03a56f
symfony-classic process model: FrankenPHP classic mode (no worker), PHP_THREADS = 2×CPUs; campfire:cable on 127.0.0.1:4391, messenger:consume; user 0:0
symfony image: campfire-symfony:app linux/arm64 sha256:aeabf684ed8d7fe95489ad715059c1de31535ff3c6ba9b4dc5f708b73e03a56f revision none(label-inherited-from-FrankenPHP-233f793) source  created 2026-10-06T09:13:32.347124452+02:00 digests campfire-symfony@sha256:aeabf684ed8d7fe95489ad715059c1de31535ff3c6ba9b4dc5f708b73e03a56f
symfony process model: image defaults: FrankenPHP worker mode, campfire:cable on 127.0.0.1:4391, messenger:consume; user 0:0
redis sidecar image: redis:7-alpine linux/arm64/v8 sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99 redis@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99
```

Reps: Rails 3, Django 3, Laravel 3, Laravel FrankenPHP classic 3, Laravel Octane 3, Express 3, Elixir 3, Go 3, Rust 3, Symfony classic 3, Symfony 3. Cells: median [min–max] across reps.

### Validation

Each rep must sign in, find the CSRF token, stream sources and stylesheet, get only 2xx/3xx with no transport
errors, persist every acknowledged post (a new message with its rich text body, in the FTS index; integrity_check ok),
deliver every cable message to every client, and serve the uploaded image's thumbnail.

| App | Rep | Result |
|---|---|---|
| Rails | 1 | pass |
| Rails | 2 | pass |
| Rails | 3 | pass |
| Django | 1 | **FAIL**: cable 100 clients: saturated 7/4167 messages reached every client; cable 1000 clients: saturated 1/3387 messages reached every client |
| Django | 2 | **FAIL**: cable 100 clients: saturated 6/4364 messages reached every client; cable 1000 clients: saturated 1/4842 messages reached every client |
| Django | 3 | **FAIL**: cable 100 clients: saturated 6/4383 messages reached every client; cable 1000 clients: saturated 1/4103 messages reached every client |
| Laravel | 1 | **FAIL**: cable 1000 clients: saturated 334/409 messages reached every client |
| Laravel | 2 | **FAIL**: cable 1000 clients: saturated 338/466 messages reached every client |
| Laravel | 3 | **FAIL**: cable 1000 clients: saturated 334/500 messages reached every client |
| Laravel FrankenPHP classic | 1 | **FAIL**: cable 1000 clients: saturated 372/2361 messages reached every client |
| Laravel FrankenPHP classic | 2 | **FAIL**: cable 1000 clients: saturated 372/2298 messages reached every client |
| Laravel FrankenPHP classic | 3 | **FAIL**: cable 1000 clients: saturated 369/2374 messages reached every client |
| Laravel Octane | 1 | **FAIL**: cable 100 clients: saturated 3878/7399 messages reached every client; cable 1000 clients: saturated 377/7242 messages reached every client |
| Laravel Octane | 2 | **FAIL**: cable 100 clients: saturated 3906/7411 messages reached every client; cable 1000 clients: saturated 376/7093 messages reached every client |
| Laravel Octane | 3 | **FAIL**: cable 100 clients: saturated 3870/7383 messages reached every client; cable 1000 clients: saturated 379/7222 messages reached every client |
| Express | 1 | pass |
| Express | 2 | pass |
| Express | 3 | **FAIL**: cable 100 clients: saturated 6437/20723 messages reached every client |
| Elixir | 1 | pass |
| Elixir | 2 | pass |
| Elixir | 3 | pass |
| Go | 1 | pass |
| Go | 2 | pass |
| Go | 3 | pass |
| Rust | 1 | pass |
| Rust | 2 | pass |
| Rust | 3 | pass |
| Symfony classic | 1 | pass |
| Symfony classic | 2 | pass |
| Symfony classic | 3 | pass |
| Symfony | 1 | pass |
| Symfony | 2 | pass |
| Symfony | 3 | pass |

### HTTP throughput, 16 concurrent clients (req/s, median)

| Route | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Room page | 254 | 249 | 124 | 120 | 179 | 615 | 460 | 4,169 | 22,541 | 174 | 713 |
| Messages page | 439 | 289 | 142 | 137 | 221 | 797 | 716 | 5,236 | 23,907 | 350 | 1,620 |
| Sidebar | 589 | 1,020 | 328 | 319 | 1,813 | 5,802 | 947 | 12,489 | 22,302 | 217 | 2,701 |
| Search | 456 | 462 | 219 | 211 | 526 | 1,530 | 778 | 6,836 | 23,561 | 300 | 1,495 |
| Post a message | 274 | 228 | 39.3 | 226 | 599 | 1,682 | 533 | 4,545 | 6,703 | 262 | 1,652 |
| /up | 4,149 | 2,180 | 1,202 | 1,145 | 4,561 | 27,117 | 6,677 | 73,239 | 93,307 | 712 | 11,131 |

×Rails (>1: more req/s than Rails):

| Route | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Room page | 0.98× | 0.49× | 0.47× | 0.71× | 2.42× | 1.81× | 16.43× | 88.85× | 0.69× | 2.81× |
| Messages page | 0.66× | 0.32× | 0.31× | 0.50× | 1.82× | 1.63× | 11.93× | 54.48× | 0.80× | 3.69× |
| Sidebar | 1.73× | 0.56× | 0.54× | 3.08× | 9.85× | 1.61× | 21.20× | 37.85× | 0.37× | 4.58× |
| Search | 1.01× | 0.48× | 0.46× | 1.15× | 3.35× | 1.71× | 14.99× | 51.66× | 0.66× | 3.28× |
| Post a message | 0.83× | 0.14× | 0.82× | 2.18× | 6.13× | 1.94× | 16.56× | 24.42× | 0.95× | 6.02× |
| /up | 0.53× | 0.29× | 0.28× | 1.10× | 6.54× | 1.61× | 17.65× | 22.49× | 0.17× | 2.68× |

### What one request returns (untimed GET per rep, same cookie and Accept-Encoding as loadgen)

Messages shown, all elements (start tags), decoded HTML, gzip wire size, visible text, and the hidden CSRF inputs
with their distinct values. The work behind a request is only comparable between apps that show the same messages
with comparable markup; "window differs" marks message ids (in order) unlike the first app's. A CSRF token masked
per form (Rails, Symfony: one distinct value per form) is incompressible, so those pages are about twice as big
on the wire as pages that repeat one token, for the same HTML.

| Page | Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Room page | messages | 40 | 40 | 40 | 40 | 40 | 40 | 40 | 40 | 40 | 40 | 40 |
| Room page | elements | 4,000 | 4,000 | 4,027 | 4,027 | 4,027 | 4,000 | 4,000 | 3,675 | 3,675 | 4,000 | 4,000 |
| Room page | decoded KB | 453 | 410 | 390 | 390 | 390 | 410 | 453 | 365 | 406 | 452 | 452 |
| Room page | gzip KB | 43.3 | 20.1 | 28.3 | 19.6 | 19.6 | 19.9 | 20.7 | 20.8 | 23.7 | 44.0 | 43.9 |
| Room page | visible text KB | 17.7 | 17.6 | 24.9 | 24.9 | 24.9 | 17.6 | 17.7 | 17.7 | 17.7 | 17.6 | 17.6 |
| Room page | CSRF inputs (distinct values) | 323 (323) | 323 (1) | 644 (1) | 644 (1) | 644 (1) | 323 (1) | 323 (2) | 0 (0) | 0 (0) | 323 (323) | 323 (323) |
| Messages page | messages | 40 | 40 | 40 | 40 | 40 | 40 | 40 | 40 | 40 | 40 | 40 |
| Messages page | elements | 3,720 | 3,400 | 3,880 | 3,880 | 3,880 | 3,400 | 3,720 | 3,400 | 3,400 | 3,720 | 3,720 |
| Messages page | decoded KB | 421 | 333 | 371 | 371 | 371 | 333 | 421 | 334 | 375 | 421 | 421 |
| Messages page | gzip KB | 34.0 | 10.9 | 19.2 | 13.0 | 13.0 | 10.7 | 11.8 | 12.5 | 15.8 | 35.1 | 35.1 |
| Messages page | visible text KB | 8.6 | 8.6 | 14.8 | 14.8 | 14.8 | 8.6 | 8.6 | 8.6 | 8.6 | 8.6 | 8.6 |
| Messages page | CSRF inputs (distinct values) | 320 (320) | 0 (0) | 640 (1) | 640 (1) | 640 (1) | 0 (0) | 320 (1) | 0 (0) | 0 (0) | 320 (320) | 320 (320) |
| Search | messages | 13 | 13 | 13 | 13 | 13 | 13 | 13 | 13 | 13 | 13 | 13 |
| Search | elements | 1,420 | 1,420 | 1,338 | 1,338 | 1,338 | 1,420 | 1,420 | 1,311 | 1,311 | 1,420 | 1,420 |
| Search | decoded KB | 162 | 147 | 133 | 133 | 133 | 147 | 162 | 132 | 146 | 161 | 161 |
| Search | gzip KB | 17.1 | 9.1 | 10.9 | 8.0 | 8.0 | 9.1 | 10.0 | 9.2 | 9.5 | 17.3 | 17.3 |
| Search | visible text KB | 10.1 | 10.1 | 11.9 | 11.9 | 11.9 | 10.1 | 10.1 | 10.1 | 10.1 | 10.0 | 10.0 |
| Search | CSRF inputs (distinct values) | 107 (107) | 107 (1) | 208 (1) | 208 (1) | 208 (1) | 107 (1) | 107 (5) | 0 (0) | 0 (0) | 107 (107) | 107 (107) |
| Sidebar | messages | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| Sidebar | elements | 248 | 63 | 22 | 22 | 22 | 63 | 248 | 84 | 243 | 248 | 248 |
| Sidebar | decoded KB | 31 | 7 | 3 | 3 | 3 | 7 | 31 | 9 | 30 | 31 | 31 |
| Sidebar | gzip KB | 6.1 | 1.9 | 0.8 | 0.8 | 0.8 | 1.9 | 6.0 | 2.2 | 5.8 | 6.2 | 6.2 |
| Sidebar | visible text KB | 7.7 | 0.4 | 0.2 | 0.2 | 0.2 | 0.4 | 7.7 | 0.5 | 7.7 | 7.6 | 7.6 |
| Sidebar | CSRF inputs (distinct values) | 3 (3) | 0 (0) | 0 (0) | 0 (0) | 0 (0) | 0 (0) | 3 (1) | 0 (0) | 0 (0) | 3 (3) | 3 (3) |

### Laravel vs Symfony (same FrankenPHP runtime)

Both on FrankenPHP with PHP 8.4, the same thread counts and the same SQLite; classic = no worker (the framework
boots on every request), worker = booted once per thread (Laravel Octane / Symfony runtime). Medians; S/L > 1 means
Symfony does better (more req/s or msgs/s, fewer ms, bytes or MB). HTTP at c=16.

| Metric | Laravel FrankenPHP classic | Symfony classic | S/L classic | Laravel Octane | Symfony | S/L worker |
|---|---:|---:|---:|---:|---:|---:|
| Room page req/s | 120 | 174 | 1.45× | 179 | 713 | 3.98× |
| Messages page req/s | 137 | 350 | 2.56× | 221 | 1,620 | 7.34× |
| Sidebar req/s | 319 | 217 | 0.68× | 1,813 | 2,701 | 1.49× |
| Search req/s | 211 | 300 | 1.42× | 526 | 1,495 | 2.84× |
| Post a message req/s | 226 | 262 | 1.16× | 599 | 1,652 | 2.76× |
| /up req/s | 1,145 | 712 | 0.62× | 4,561 | 11,131 | 2.44× |
| Room page p50 ms | 133 | 91.6 | 1.45× | 91.9 | 22.2 | 4.14× |
| Messages page p50 ms | 117 | 45.7 | 2.55× | 72.1 | 9.45 | 7.63× |
| Sidebar p50 ms | 50.0 | 73.6 | 0.68× | 8.41 | 5.38 | 1.56× |
| Search p50 ms | 75.5 | 53.1 | 1.42× | 30.2 | 10.4 | 2.90× |
| Post a message p50 ms | 67.4 | 61.0 | 1.10× | 20.5 | 7.98 | 2.57× |
| Room page avg response bytes (gzip) | 20,031 | 45,025 | 0.44× | 20,030 | 45,044 | 0.44× |
| Messages page avg response bytes (gzip) | 13,311 | 35,934 | 0.37× | 13,310 | 35,932 | 0.37× |
| Sidebar avg response bytes (gzip) | 817 | 6,395 | 0.13× | 817 | 6,395 | 0.13× |
| Search avg response bytes (gzip) | 8,215 | 17,707 | 0.46× | 8,212 | 17,712 | 0.46× |
| Post a message avg response bytes (gzip) | 1,973 | 2,031 | 0.97× | 1,972 | 2,033 | 0.97× |
| Cable 1000 clients: delivered msgs/s | 5.00 | 110 | 21.90× | 5.00 | 172 | 34.32× |
| Cable 1000 clients: paced post→all p50 ms | 319 | 59.6 | 5.35× | 303 | 41.0 | 7.41× |
| Upload: POST → first <img> (ms) | 79.3 | 62.5 | 1.27× | 57.5 | 35.7 | 1.61× |
| Idle memory.current (MB) | 227 | 227 | 1.00× | 211 | 229 | 0.92× |
| Peak memory.current (MB) | 540 | 802 | 0.67× | 818 | 860 | 0.95× |
| Cold start (ms) | 903 | 772 | 1.17× | 911 | 775 | 1.18× |

Do the two apps render equivalent pages? (first rep of each; the throughput ratios above only compare like
work if they do)

| Mode | Page | Same message window | Messages L / S | Elements L / S | Decoded KB L / S | Visible text KB L / S | gzip KB L / S | CSRF inputs (distinct) L / S | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| classic | Room page | yes | 40 / 40 | 4,027 / 4,000 | 390 / 452 | 24.9 / 17.6 | 19.6 / 44.0 | 644 (1) / 323 (323) | equivalent |
| classic | Messages page | yes | 40 / 40 | 3,880 / 3,720 | 371 / 421 | 14.8 / 8.6 | 13.0 / 35.1 | 640 (1) / 320 (320) | equivalent |
| classic | Search | yes | 13 / 13 | 1,338 / 1,420 | 133 / 161 | 11.9 / 10.0 | 8.0 / 17.3 | 208 (1) / 107 (107) | equivalent |
| classic | Sidebar | yes | 0 / 0 | 22 / 248 | 3 / 31 | 0.2 / 7.6 | 0.8 / 6.2 | 0 (0) / 3 (3) | **different**: elements ×0.09, HTML size ×0.09 |
| worker | Room page | yes | 40 / 40 | 4,027 / 4,000 | 390 / 452 | 24.9 / 17.6 | 19.6 / 43.9 | 644 (1) / 323 (323) | equivalent |
| worker | Messages page | yes | 40 / 40 | 3,880 / 3,720 | 371 / 421 | 14.8 / 8.6 | 13.0 / 35.1 | 640 (1) / 320 (320) | equivalent |
| worker | Search | yes | 13 / 13 | 1,338 / 1,420 | 133 / 161 | 11.9 / 10.0 | 8.0 / 17.3 | 208 (1) / 107 (107) | equivalent |
| worker | Sidebar | yes | 0 / 0 | 22 / 248 | 3 / 31 | 0.2 / 7.6 | 0.8 / 6.2 | 0 (0) / 3 (3) | **different**: elements ×0.09, HTML size ×0.09 |

### Startup and memory

| Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---|---|---|---|---|---|---|---|---|---|---|
| cold start: docker run → /up 200 (ms) | 2,402 [2,369–3,172] | 858 [848–1,118] | 820 [728–1,104] | 903 [827–1,045] | 911 [885–924] | 777 [618–1,008] | 798 [796–976] | 431 [362–545] | 403 [345–493] | 772 [736–830] | 775 [773–797] |
| idle memory.current (MB) | 438 [431–440] | 289 [289–290] | 156 [156–156] | 227 [166–228] | 211 [179–269] | 271 [270–272] | 189 [176–202] | 48.0 [48.0–48.0] | 65.0 [63.0–66.0] | 227 [218–228] | 229 [221–229] |
| idle anon (MB) | 284 [283–287] | 223 [223–223] | 70.0 [70.0–70.0] | 83.0 [83.0–84.0] | 121 [115–121] | 162 [162–164] | 119 [110–122] | 9.00 [9.00–9.00] | 15.0 [15.0–15.0] | 64.0 [64.0–65.0] | 67.0 [67.0–67.0] |
| peak memory.current under load (MB) | 1,527 [1,512–1,559] | 1,169 [1,099–1,182] | 327 [302–351] | 540 [528–564] | 818 [817–837] | 2,805 [2,649–2,939] | 708 [694–755] | 483 [444–493] | 452 [444–457] | 802 [795–817] | 860 [856–884] |
| peak anon under load (MB) | 1,478 [1,464–1,512] | 1,060 [993–1,077] | 182 [160–203] | 357 [343–372] | 507 [492–521] | 2,698 [2,522–2,831] | 567 [553–611] | 378 [336–387] | 302 [294–306] | 496 [477–500] | 507 [501–518] |
| Redis sidecar idle memory.current (MB, not in the above) | – | 25.0 [25.0–25.0] | – | – | – | – | – | – | – | – | – |
| Redis sidecar peak memory (MB, not in the above) | – | 26.0 [26.0–29.0] | – | – | – | – | – | – | – | – | – |

### HTTP (signed in as david; keep-alive; c = concurrent connections)

| Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---|---|---|---|---|---|---|---|---|---|---|
| room_show c=1 req/s | 113 [112–114] | 78.2 [78.0–79.9] | 33.8 [33.5–34.0] | 32.8 [32.2–32.9] | 46.9 [46.8–47.3] | 212 [208–213] | 321 [317–321] | 1,036 [1,032–1,038] | 6,953 [6,942–6,991] | 48.8 [48.6–49.1] | 209 [206–209] |
| room_show c=1 p50 ms | 8.74 [8.73–8.77] | 12.2 [11.9–12.2] | 29.5 [29.2–29.8] | 30.5 [30.3–31.1] | 21.4 [21.1–21.4] | 4.54 [4.54–4.64] | 3.08 [3.04–3.08] | 0.86 [0.86–0.86] | 0.14 [0.14–0.14] | 20.5 [20.3–20.5] | 4.70 [4.69–4.78] |
| room_show c=1 p99 ms | 11.1 [10.4–12.2] | 18.4 [17.6–19.4] | 32.7 [31.2–38.0] | 33.1 [32.6–33.2] | 22.1 [22.0–22.7] | 8.26 [8.17–8.48] | 5.00 [4.42–5.87] | 4.62 [4.59–4.70] | 0.17 [0.17–0.17] | 21.4 [21.2–22.5] | 5.26 [5.18–5.45] |
| room_show c=16 req/s | 254 [252–259] | 249 [214–250] | 124 [124–124] | 120 [119–120] | 179 [179–180] | 615 [614–618] | 460 [460–476] | 4,169 [4,164–4,206] | 22,541 [22,516–22,542] | 174 [174–174] | 713 [709–713] |
| room_show c=16 p50 ms | 59.2 [33.9–62.5] | 59.6 [48.0–60.1] | 129 [129–129] | 133 [132–134] | 91.9 [91.6–92.9] | 24.9 [24.9–25.2] | 34.8 [33.6–35.0] | 2.81 [2.77–2.93] | 0.61 [0.61–0.62] | 91.6 [91.6–91.7] | 22.2 [22.1–22.2] |
| room_show c=16 p99 ms | 128 [118–237] | 145 [132–215] | 170 [159–178] | 192 [177–196] | 135 [126–138] | 46.0 [44.7–47.5] | 47.3 [44.7–47.9] | 12.6 [12.2–13.0] | 2.08 [2.07–2.09] | 124 [114–125] | 36.1 [35.9–38.1] |
| room_show c=64 req/s | 233 [231–239] | 241 [240–243] | 123 [121–123] | 120 [119–120] | 210 [210–211] | 633 [629–646] | 518 [485–526] | 4,105 [4,082–4,122] | 22,917 [22,761–23,266] | 174 [174–174] | 718 [717–720] |
| room_show c=64 p50 ms | 266 [263–277] | 242 [151–266] | 518 [518–525] | 532 [532–534] | 303 [303–305] | 98.2 [96.3–98.9] | 123 [121–132] | 13.8 [13.8–14.0] | 2.62 [2.58–2.62] | 366 [366–367] | 88.6 [88.4–88.8] |
| room_show c=64 p99 ms | 347 [344–382] | 564 [425–842] | 587 [572–588] | 591 [590–592] | 332 [331–334] | 153 [149–155] | 151 [148–155] | 55.3 [46.7–57.5] | 6.27 [6.14–6.46] | 398 [397–405] | 103 [102–106] |
| messages_page c=1 req/s | 210 [209–213] | 89.4 [88.9–91.3] | 38.2 [38.0–38.2] | 36.7 [36.3–36.8] | 60.4 [60.3–60.6] | 274 [270–275] | 399 [399–404] | 1,301 [1,300–1,307] | 7,333 [7,272–7,380] | 97.5 [97.5–98.1] | 538 [534–538] |
| messages_page c=1 p50 ms | 4.60 [4.57–4.69] | 10.3 [10.2–10.3] | 26.1 [26.0–26.4] | 27.2 [27.2–27.4] | 16.5 [16.5–16.5] | 3.50 [3.48–3.54] | 2.46 [2.42–2.46] | 0.71 [0.71–0.72] | 0.14 [0.13–0.14] | 10.1 [10.1–10.2] | 1.84 [1.83–1.85] |
| messages_page c=1 p99 ms | 6.12 [6.09–7.05] | 16.9 [16.6–21.8] | 27.6 [26.8–28.5] | 29.3 [29.1–30.1] | 17.2 [17.0–17.4] | 6.81 [6.72–7.06] | 4.08 [3.49–4.35] | 3.25 [3.25–3.32] | 0.17 [0.17–0.17] | 10.9 [10.8–11.1] | 2.03 [2.03–2.11] |
| messages_page c=16 req/s | 439 [435–442] | 289 [287–293] | 142 [142–142] | 137 [136–137] | 221 [220–221] | 797 [794–798] | 716 [707–897] | 5,236 [5,198–5,257] | 23,907 [23,389–24,092] | 350 [350–350] | 1,620 [1,594–1,639] |
| messages_page c=16 p50 ms | 34.7 [30.6–35.5] | 50.5 [42.3–52.2] | 113 [112–113] | 117 [116–117] | 72.1 [72.0–72.3] | 19.4 [19.2–19.5] | 22.2 [15.8–22.4] | 2.23 [2.22–2.23] | 0.58 [0.58–0.60] | 45.7 [45.6–45.7] | 9.45 [9.32–9.58] |
| messages_page c=16 p99 ms | 87.6 [82.0–121.0] | 135 [121–150] | 148 [145–150] | 165 [165–167] | 101 [100–102] | 37.5 [36.2–38.5] | 34.3 [33.0–34.3] | 9.77 [9.57–9.82] | 1.97 [1.83–1.97] | 60.6 [59.7–61.0] | 21.2 [21.1–21.6] |
| messages_page c=64 req/s | 423 [399–426] | 281 [276–282] | 142 [142–142] | 136 [136–137] | 222 [221–222] | 808 [808–814] | 715 [711–729] | 5,158 [5,147–5,205] | 24,656 [24,536–24,741] | 349 [349–350] | 1,734 [1,727–1,740] |
| messages_page c=64 p50 ms | 153 [149–156] | 167 [50–218] | 451 [450–452] | 467 [466–469] | 288 [287–288] | 75.8 [74.8–76.6] | 88.8 [86.7–90.7] | 9.48 [9.27–9.65] | 2.45 [2.45–2.46] | 183 [182–183] | 36.2 [36.1–36.4] |
| messages_page c=64 p99 ms | 218 [204–230] | 532 [498–866] | 484 [482–494] | 516 [514–518] | 316 [314–318] | 124 [122–130] | 120 [114–121] | 51.6 [47.8–52.9] | 5.62 [5.51–5.66] | 198 [197–199] | 59.3 [58.8–59.3] |
| sidebar c=1 req/s | 276 [268–278] | 357 [351–362] | 87.3 [85.4–87.9] | 85.6 [85.0–86.1] | 526 [521–527] | 1,870 [1,854–1,874] | 773 [767–822] | 4,277 [4,268–4,324] | 6,704 [6,691–6,774] | 58.6 [58.3–58.9] | 820 [802–833] |
| sidebar c=1 p50 ms | 3.52 [3.49–3.65] | 2.79 [2.75–2.83] | 11.4 [11.4–11.7] | 11.6 [11.6–11.8] | 1.87 [1.87–1.89] | 0.49 [0.49–0.50] | 1.23 [1.16–1.23] | 0.20 [0.20–0.20] | 0.15 [0.15–0.15] | 16.9 [16.7–17.0] | 1.17 [1.15–1.21] |
| sidebar c=1 p99 ms | 4.89 [4.84–5.00] | 3.17 [3.13–3.24] | 12.4 [11.8–12.7] | 12.5 [12.2–12.6] | 2.12 [2.12–2.16] | 1.16 [0.96–1.19] | 2.87 [2.70–3.22] | 0.73 [0.71–0.77] | 0.18 [0.18–0.18] | 18.0 [18.0–18.1] | 1.40 [1.38–1.42] |
| sidebar c=16 req/s | 589 [555–590] | 1,020 [985–1,040] | 328 [326–328] | 319 [317–320] | 1,813 [1,606–1,815] | 5,802 [5,638–5,822] | 947 [937–962] | 12,489 [12,426–13,238] | 22,302 [21,943–22,444] | 217 [216–217] | 2,701 [2,665–2,711] |
| sidebar c=16 p50 ms | 26.2 [25.9–27.1] | 14.9 [13.2–16.8] | 48.6 [48.5–48.9] | 50.0 [49.9–50.2] | 8.41 [8.38–8.50] | 2.37 [2.33–2.41] | 16.4 [16.3–16.5] | 1.03 [0.98–1.04] | 0.62 [0.62–0.62] | 73.6 [73.5–73.7] | 5.38 [5.35–5.50] |
| sidebar c=16 p99 ms | 55.9 [51.7–61.4] | 30.8 [27.6–38.4] | 56.9 [56.9–62.7] | 69.0 [67.8–70.5] | 18.3 [17.8–18.5] | 11.3 [11.1–12.0] | 26.4 [25.1–29.2] | 5.39 [4.94–5.46] | 2.08 [2.03–2.13] | 102 [100–103] | 14.0 [14.0–14.1] |
| sidebar c=64 req/s | 586 [486–593] | 1,000 [990–1,006] | 326 [325–327] | 316 [316–317] | 1,510 [1,462–1,820] | 6,200 [6,142–6,221] | 925 [898–928] | 12,158 [12,129–12,519] | 22,707 [22,164–22,790] | 217 [217–217] | 2,893 [2,883–2,899] |
| sidebar c=64 p50 ms | 110 [97–132] | 58.5 [49.0–64.8] | 196 [196–196] | 202 [201–202] | 34.5 [34.3–34.7] | 9.08 [8.94–9.66] | 69.1 [68.6–70.8] | 4.74 [4.59–4.79] | 2.63 [2.62–2.69] | 294 [294–294] | 21.7 [21.6–21.7] |
| sidebar c=64 p99 ms | 212 [188–218] | 149 [142–158] | 209 [208–211] | 220 [220–221] | 48.7 [45.5–50.5] | 22.8 [22.6–23.2] | 91.9 [89.6–92.2] | 13.1 [13.0–13.3] | 6.43 [6.19–6.51] | 324 [323–325] | 37.1 [37.0–37.9] |
| search c=1 req/s | 215 [201–215] | 149 [148–150] | 57.6 [57.3–57.7] | 55.7 [55.6–56.0] | 145 [145–145] | 476 [474–477] | 613 [600–631] | 1,809 [1,786–1,831] | 5,938 [5,893–5,961] | 82.3 [81.4–83.2] | 430 [424–430] |
| search c=1 p50 ms | 4.57 [4.55–4.92] | 6.32 [6.24–6.37] | 17.3 [17.3–17.4] | 17.8 [17.8–17.9] | 6.78 [6.78–6.81] | 1.99 [1.98–1.99] | 1.58 [1.53–1.58] | 0.48 [0.46–0.48] | 0.17 [0.17–0.17] | 12.1 [11.9–12.3] | 2.29 [2.29–2.32] |
| search c=1 p99 ms | 6.17 [5.98–6.17] | 11.4 [11.3–11.4] | 19.9 [19.8–20.0] | 20.2 [19.9–20.5] | 8.37 [8.20–8.43] | 7.23 [7.03–7.31] | 3.86 [3.44–4.21] | 3.75 [3.51–3.85] | 0.21 [0.21–0.21] | 12.8 [12.8–13.1] | 2.57 [2.51–2.58] |
| search c=16 req/s | 456 [434–458] | 462 [409–484] | 219 [219–219] | 211 [211–212] | 526 [525–528] | 1,530 [1,501–1,539] | 778 [765–781] | 6,836 [6,826–6,938] | 23,561 [23,214–23,649] | 300 [300–301] | 1,495 [1,494–1,518] |
| search c=16 p50 ms | 33.1 [31.8–33.5] | 30.2 [16.8–34.9] | 73.0 [72.9–73.0] | 75.5 [75.5–75.5] | 30.2 [30.2–30.2] | 9.67 [9.57–9.90] | 20.5 [20.1–20.6] | 1.68 [1.62–1.69] | 0.63 [0.63–0.64] | 53.1 [53.0–53.2] | 10.4 [10.3–10.5] |
| search c=16 p99 ms | 78.1 [76.1–108.8] | 81.2 [73.4–124.2] | 100 [95–105] | 104 [97–105] | 44.2 [43.3–45.5] | 23.4 [23.0–23.5] | 29.7 [29.0–30.9] | 9.77 [9.57–9.78] | 1.45 [1.45–1.49] | 69.8 [69.4–73.0] | 21.1 [19.8–21.2] |
| search c=64 req/s | 460 [389–463] | 469 [465–470] | 219 [218–219] | 211 [211–212] | 485 [482–529] | 1,531 [1,524–1,532] | 791 [784–810] | 6,575 [6,571–6,663] | 28,668 [28,404–28,681] | 301 [301–301] | 1,529 [1,526–1,529] |
| search c=64 p50 ms | 143 [139–162] | 37.0 [36.3–48.8] | 293 [292–293] | 302 [301–303] | 120 [120–120] | 39.9 [39.7–40.2] | 80.7 [78.2–81.3] | 8.84 [8.60–8.86] | 2.07 [2.07–2.10] | 212 [212–213] | 41.4 [41.4–41.5] |
| search c=64 p99 ms | 196 [191–249] | 520 [502–537] | 317 [317–319] | 330 [326–330] | 803 [136–865] | 71.4 [69.8–73.3] | 99.6 [96.7–102.2] | 27.5 [27.4–32.0] | 4.62 [4.57–4.63] | 231 [229–231] | 55.8 [55.2–55.9] |
| avatar c=1 req/s | 9,255 [9,188–9,319] | 402 [400–405] | 141 [137–141] | 134 [133–135] | 988 [938–999] | 3,881 [3,784–3,934] | 9,341 [9,327–9,377] | 14,139 [14,105–14,325] | 18,341 [18,133–18,578] | 80.5 [80.3–83.5] | 1,490 [1,462–1,543] |
| avatar c=1 p50 ms | 0.08 [0.08–0.08] | 2.44 [2.42–2.46] | 7.04 [7.01–7.28] | 7.40 [7.40–7.50] | 0.96 [0.95–0.99] | 0.24 [0.23–0.24] | 0.08 [0.08–0.08] | 0.07 [0.06–0.07] | 0.06 [0.05–0.06] | 12.5 [11.8–12.5] | 0.63 [0.60–0.65] |
| avatar c=1 p99 ms | 0.97 [0.94–0.98] | 3.40 [3.16–3.62] | 8.17 [8.10–8.48] | 8.70 [8.43–9.00] | 1.54 [1.53–1.99] | 0.32 [0.32–0.33] | 0.99 [0.96–1.03] | 0.09 [0.09–0.09] | 0.07 [0.07–0.07] | 13.6 [12.8–13.6] | 0.77 [0.75–0.78] |
| avatar c=16 req/s | 17,203 [17,182–17,862] | 1,152 [1,134–1,179] | 526 [524–527] | 504 [503–505] | 3,128 [3,118–3,140] | 13,028 [12,465–13,086] | 17,908 [17,856–18,365] | 78,632 [78,226–80,771] | 126,895 [125,936–129,265] | 304 [303–304] | 4,784 [4,762–4,863] |
| avatar c=16 p50 ms | 0.33 [0.33–0.34] | 10.5 [9.4–13.1] | 30.3 [30.3–30.4] | 31.6 [31.6–31.6] | 4.65 [4.64–4.69] | 1.03 [1.00–1.04] | 0.31 [0.30–0.32] | 0.12 [0.11–0.12] | 0.10 [0.09–0.10] | 52.6 [52.6–52.7] | 2.86 [2.78–2.89] |
| avatar c=16 p99 ms | 7.15 [6.74–7.23] | 36.2 [24.7–40.6] | 40.1 [39.0–41.0] | 44.7 [42.8–45.1] | 12.6 [12.5–13.0] | 4.65 [4.64–4.74] | 6.97 [6.84–7.01] | 2.21 [2.03–2.22] | 0.53 [0.51–0.53] | 72.8 [72.4–73.3] | 9.19 [9.05–9.30] |
| avatar c=64 req/s | 18,450 [18,037–18,993] | 1,128 [1,126–1,134] | 525 [525–526] | 504 [503–504] | 3,219 [2,362–3,255] | 13,459 [13,442–13,604] | 18,423 [18,250–19,307] | 85,689 [85,293–85,732] | 137,497 [136,655–138,325] | 304 [303–304] | 5,285 [5,284–5,294] |
| avatar c=64 p50 ms | 1.10 [0.98–1.23] | 26.4 [22.1–58.4] | 122 [121–122] | 127 [127–127] | 19.1 [18.9–19.4] | 4.06 [3.78–4.30] | 1.12 [1.09–1.15] | 0.35 [0.35–0.35] | 0.36 [0.36–0.37] | 210 [210–211] | 11.4 [11.3–11.4] |
| avatar c=64 p99 ms | 19.6 [19.3–19.9] | 188 [104–199] | 131 [129–131] | 140 [139–140] | 31.6 [30.9–32.6] | 18.5 [18.4–19.0] | 19.5 [18.9–19.6] | 5.69 [5.61–5.82] | 1.74 [1.71–1.79] | 231 [230–232] | 24.9 [24.5–25.1] |
| static_css c=1 req/s | 11,806 [11,581–11,853] | 634 [630–640] | 18,604 [18,071–18,811] | 12,357 [12,089–12,606] | 12,555 [12,457–12,791] | 4,267 [4,262–4,344] | 11,904 [11,696–11,924] | 17,593 [17,550–18,301] | 18,897 [18,549–19,054] | 12,888 [12,751–12,995] | 12,180 [12,060–12,747] |
| static_css c=1 p50 ms | 0.07 [0.07–0.07] | 1.54 [1.53–1.56] | 0.05 [0.05–0.05] | 0.07 [0.07–0.08] | 0.07 [0.07–0.07] | 0.24 [0.24–0.24] | 0.07 [0.07–0.07] | 0.05 [0.05–0.05] | 0.05 [0.05–0.06] | 0.07 [0.07–0.07] | 0.08 [0.07–0.08] |
| static_css c=1 p99 ms | 0.13 [0.12–0.13] | 2.04 [2.02–2.04] | 0.07 [0.07–0.07] | 0.10 [0.10–0.10] | 0.10 [0.10–0.10] | 0.29 [0.28–0.29] | 0.12 [0.12–0.14] | 0.08 [0.07–0.08] | 0.07 [0.07–0.07] | 0.10 [0.10–0.10] | 0.10 [0.10–0.10] |
| static_css c=16 req/s | 26,504 [25,745–26,693] | 1,772 [1,707–1,796] | 119,604 [119,484–120,507] | 68,581 [67,652–68,824] | 67,860 [66,782–67,961] | 21,875 [21,745–23,216] | 26,037 [25,852–26,393] | 104,840 [103,940–105,428] | 126,343 [125,890–127,036] | 70,063 [69,449–70,095] | 71,614 [69,290–72,457] |
| static_css c=16 p50 ms | 0.22 [0.22–0.23] | 9.04 [8.61–10.13] | 0.13 [0.13–0.13] | 0.18 [0.18–0.18] | 0.18 [0.18–0.18] | 0.56 [0.52–0.57] | 0.22 [0.22–0.22] | 0.08 [0.08–0.08] | 0.10 [0.10–0.10] | 0.18 [0.17–0.18] | 0.17 [0.17–0.18] |
| static_css c=16 p99 ms | 5.80 [5.67–5.85] | 17.2 [16.6–20.8] | 0.18 [0.18–0.20] | 1.26 [1.25–1.34] | 1.42 [1.35–1.44] | 4.55 [4.09–4.66] | 5.94 [5.91–6.04] | 1.29 [1.25–1.34] | 0.52 [0.52–0.53] | 1.26 [1.23–1.36] | 1.18 [1.11–1.29] |
| static_css c=64 req/s | 26,096 [25,660–27,375] | 1,757 [1,745–1,766] | 120,687 [120,467–121,737] | 72,122 [71,561–73,487] | 72,926 [72,746–74,166] | 24,854 [24,323–25,239] | 26,070 [25,613–26,647] | 118,400 [118,015–118,966] | 141,915 [141,093–145,153] | 74,171 [74,106–74,565] | 75,553 [74,020–75,906] |
| static_css c=64 p50 ms | 0.76 [0.74–0.80] | 33.0 [16.4–37.9] | 0.53 [0.52–0.53] | 0.64 [0.64–0.65] | 0.64 [0.63–0.64] | 2.18 [2.17–2.20] | 0.79 [0.75–0.81] | 0.23 [0.23–0.23] | 0.34 [0.33–0.34] | 0.63 [0.62–0.63] | 0.62 [0.62–0.63] |
| static_css c=64 p99 ms | 14.4 [14.2–15.1] | 69.0 [67.9–129.4] | 0.70 [0.69–0.72] | 4.44 [4.10–4.58] | 4.25 [4.25–4.32] | 9.89 [9.35–10.24] | 14.7 [14.5–14.7] | 4.78 [4.70–4.83] | 1.81 [1.78–1.82] | 4.17 [4.15–4.46] | 4.19 [4.19–4.24] |
| up c=1 req/s | 1,902 [1,832–1,937] | 767 [763–773] | 331 [326–337] | 312 [311–312] | 1,334 [1,281–1,460] | 6,687 [6,682–6,874] | 3,230 [3,159–3,239] | 13,669 [13,597–13,677] | 14,611 [14,537–14,711] | 187 [187–194] | 3,283 [3,213–3,360] |
| up c=1 p50 ms | 0.46 [0.44–0.46] | 1.29 [1.28–1.30] | 3.02 [2.96–3.04] | 3.17 [3.15–3.17] | 0.67 [0.65–0.67] | 0.14 [0.14–0.14] | 0.27 [0.27–0.27] | 0.07 [0.07–0.07] | 0.07 [0.07–0.07] | 5.37 [5.09–5.38] | 0.29 [0.28–0.29] |
| up c=1 p99 ms | 2.14 [1.88–2.16] | 1.56 [1.55–1.58] | 3.31 [3.23–3.32] | 3.50 [3.49–3.50] | 1.05 [0.86–1.18] | 0.17 [0.17–0.17] | 1.75 [1.54–1.82] | 0.09 [0.09–0.09] | 0.09 [0.09–0.09] | 5.55 [5.52–5.55] | 0.39 [0.39–0.40] |
| up c=16 req/s | 4,149 [4,147–4,204] | 2,180 [2,162–2,195] | 1,202 [1,140–1,211] | 1,145 [1,053–1,160] | 4,561 [4,547–4,644] | 27,117 [27,040–27,546] | 6,677 [6,628–6,807] | 73,239 [72,479–74,721] | 93,307 [93,161–95,871] | 712 [706–712] | 11,131 [11,010–11,159] |
| up c=16 p50 ms | 3.48 [3.44–3.50] | 7.00 [5.00–7.03] | 13.1 [13.0–13.2] | 13.7 [13.6–13.7] | 3.16 [3.11–3.21] | 0.59 [0.51–0.60] | 2.03 [2.01–2.06] | 0.13 [0.13–0.13] | 0.15 [0.14–0.15] | 22.4 [22.3–22.6] | 1.33 [1.32–1.34] |
| up c=16 p99 ms | 10.6 [10.6–10.9] | 15.4 [15.0–20.8] | 20.8 [20.2–23.2] | 24.2 [22.8–24.5] | 8.61 [8.49–8.89] | 1.79 [1.27–2.09] | 8.29 [7.83–8.32] | 1.96 [1.82–2.00] | 0.60 [0.59–0.60] | 34.3 [32.2–36.0] | 4.03 [4.00–4.16] |
| up c=64 req/s | 4,987 [4,972–5,079] | 2,199 [2,134–2,202] | 1,066 [1,049–1,214] | 1,029 [996–1,181] | 4,777 [4,737–4,918] | 26,883 [26,846–27,003] | 7,725 [7,708–7,895] | 81,566 [80,876–81,734] | 97,473 [95,795–98,575] | 712 [712–712] | 11,558 [11,554–11,627] |
| up c=64 p50 ms | 12.0 [11.6–12.0] | 15.8 [6.7–17.6] | 52.4 [52.2–53.0] | 54.4 [54.0–54.7] | 13.0 [12.5–13.0] | 2.11 [2.05–2.29] | 7.73 [7.52–7.74] | 0.38 [0.38–0.38] | 0.59 [0.58–0.60] | 89.5 [89.5–89.9] | 5.27 [5.27–5.29] |
| up c=64 p99 ms | 29.1 [29.0–29.4] | 94.1 [87.0–120.6] | 67.0 [60.7–71.6] | 73.1 [63.8–76.8] | 23.5 [23.2–23.7] | 10.5 [10.3–10.9] | 19.9 [19.8–20.2] | 5.89 [5.85–5.99] | 1.97 [1.97–1.98] | 99.8 [98.8–100.2] | 13.6 [13.4–13.8] |
| post_message c=1 req/s | 158 [148–161] | 117 [116–117] | 11.4 [9.9–12.5] | 62.6 [62.4–62.7] | 242 [228–247] | 847 [819–857] | 287 [286–292] | 2,397 [2,366–2,440] | 2,803 [2,759–2,922] | 70.2 [70.2–71.9] | 673 [671–691] |
| post_message c=1 p50 ms | 5.88 [5.77–6.07] | 7.00 [6.99–7.01] | 26.2 [18.2–26.7] | 15.4 [15.3–15.4] | 3.64 [3.60–3.65] | 1.08 [1.07–1.12] | 3.50 [3.43–3.54] | 0.38 [0.37–0.38] | 0.36 [0.34–0.36] | 14.2 [13.8–14.2] | 1.36 [1.34–1.37] |
| post_message c=1 p99 ms | 15.7 [12.1–20.0] | 23.3 [21.7–24.2] | 1,716 [884–1,946] | 28.2 [27.3–29.2] | 17.0 [16.8–18.9] | 5.01 [4.68–5.37] | 6.42 [6.24–6.61] | 0.94 [0.94–1.01] | 0.44 [0.42–0.44] | 15.3 [14.9–15.4] | 5.57 [4.91–5.59] |
| post_message c=16 req/s | 274 [263–276] | 228 [180–244] | 39.3 [25.1–53.1] | 226 [223–227] | 599 [576–609] | 1,682 [1,680–1,710] | 533 [528–533] | 4,545 [4,497–4,594] | 6,703 [6,645–6,800] | 262 [261–262] | 1,652 [1,643–1,656] |
| post_message c=16 p50 ms | 47.0 [41.4–49.1] | 32.0 [30.8–37.2] | 134 [97–164] | 67.4 [67.1–68.3] | 20.5 [20.0–20.7] | 8.02 [7.88–8.21] | 29.6 [29.3–29.7] | 2.63 [2.62–2.66] | 2.27 [2.25–2.30] | 61.0 [60.8–61.1] | 7.98 [7.96–8.00] |
| post_message c=16 p99 ms | 165 [163–182] | 773 [657–1,060] | 2,599 [1,977–4,143] | 157 [155–168] | 131 [130–154] | 31.6 [28.6–32.4] | 44.2 [42.7–45.7] | 13.9 [13.6–14.2] | 5.58 [5.54–5.72] | 79.9 [79.9–81.5] | 42.8 [42.1–43.6] |
| post_message c=64 req/s | 280 [276–289] | 174 [164–202] | 24.7 [21.1–26.5] | 225 [222–228] | 610 [610–614] | 1,860 [1,732–1,862] | 583 [569–590] | 5,100 [5,050–5,402] | 7,189 [7,096–7,268] | 261 [260–261] | 1,668 [1,665–1,669] |
| post_message c=64 p50 ms | 227 [217–233] | 68.1 [62.4–70.0] | 1,783 [1,745–2,687] | 280 [277–285] | 99.2 [98.3–99.3] | 30.8 [27.8–32.3] | 110 [108–112] | 8.74 [8.60–8.80] | 8.85 [8.75–8.94] | 245 [244–246] | 37.0 [37.0–37.1] |
| post_message c=64 p99 ms | 333 [328–345] | 3,578 [2,968–3,760] | 5,153 [3,842–5,480] | 368 [358–374] | 214 [213–222] | 73.2 [66.2–82.2] | 137 [136–142] | 57.3 [55.4–58.4] | 15.7 [15.5–16.1] | 264 [262–266] | 72.2 [70.8–72.8] |

### HTTP response size (average bytes as received, gzip where offered)

A sanity check that every app returns the full page: very different sizes deserve a look.

| Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---|---|---|---|---|---|---|---|---|---|---|
| room_show | 44,342 [44,337–44,362] | 20,556 [20,555–20,557] | 28,936 [28,933–28,955] | 20,031 [20,030–20,032] | 20,030 [20,026–20,030] | 20,353 [20,353–20,353] | 21,231 [21,228–21,232] | 21,280 [21,280–21,280] | 24,231 [24,231–24,231] | 45,025 [45,024–45,057] | 45,044 [44,987–45,045] |
| messages_page | 34,888 [34,849–34,902] | 11,072 [11,071–11,072] | 19,707 [19,703–19,718] | 13,311 [13,181–13,315] | 13,310 [13,307–13,333] | 11,003 [11,003–11,003] | 12,084 [12,083–12,085] | 12,816 [12,816–12,816] | 16,158 [16,158–16,158] | 35,934 [35,919–35,956] | 35,932 [35,915–35,953] |
| sidebar | 6,267 [6,267–6,267] | 1,957 [1,957–1,957] | 842 [842–842] | 817 [817–817] | 817 [817–817] | 1,903 [1,903–1,903] | 6,107 [6,106–6,107] | 2,249 [2,249–2,249] | 5,910 [5,910–5,910] | 6,395 [6,395–6,395] | 6,395 [6,395–6,395] |
| search | 17,524 [17,522–17,544] | 9,356 [9,356–9,356] | 11,189 [11,189–11,192] | 8,215 [8,213–8,215] | 8,212 [8,211–8,213] | 9,294 [9,293–9,294] | 10,265 [10,263–10,270] | 9,464 [9,464–9,464] | 9,766 [9,766–9,766] | 17,707 [17,701–17,743] | 17,712 [17,708–17,721] |
| avatar | 3,360 [3,360–3,360] | 3,410 [3,410–3,410] | 2,916 [2,916–2,916] | 2,930 [2,930–2,930] | 2,930 [2,930–2,930] | 4,004 [4,004–4,004] | 3,360 [3,360–3,360] | 3,389 [3,389–3,389] | 3,368 [3,368–3,368] | 3,364 [3,364–3,364] | 3,364 [3,364–3,364] |
| static_css | 653 [653–653] | 703 [703–703] | 665 [665–665] | 654 [654–654] | 654 [654–654] | 647 [647–647] | 653 [653–653] | 654 [654–654] | 654 [654–654] | 654 [654–654] | 654 [654–654] |
| up | 88.0 [88.0–88.0] | 73.0 [73.0–73.0] | 82.0 [82.0–82.0] | 73.0 [73.0–73.0] | 73.0 [73.0–73.0] | 73.0 [73.0–73.0] | 88.0 [88.0–88.0] | 98.0 [98.0–98.0] | 90.0 [90.0–90.0] | 73.0 [73.0–73.0] | 73.0 [73.0–73.0] |
| post_message | 2,002 [2,001–2,002] | 1,925 [1,924–1,925] | 2,127 [2,127–2,130] | 1,973 [1,971–1,976] | 1,972 [1,971–1,972] | 1,879 [1,879–1,879] | 2,005 [2,004–2,005] | 1,919 [1,919–1,919] | 1,992 [1,992–1,992] | 2,031 [2,031–2,032] | 2,033 [2,032–2,033] |

### HTTP errors / non-2xx-3xx (all reps)

- Rails: none
- Django: none
- Laravel: none
- Laravel FrankenPHP classic: none
- Laravel Octane: none
- Express: none
- Elixir: none
- Go: none
- Rust: none
- Symfony classic: none
- Symfony: none

### Persisted writes (post_message route, warm-up included; checked before the cable suite)

Acknowledged = 2xx/3xx responses. Each must be a new messages row with its rich text body and an FTS entry.

| Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---|---|---|---|---|---|---|---|---|---|---|
| acknowledged posts | 6,123 [6,009–6,302] | 4,715 [4,615–4,940] | 793 [677–807] | 4,582 [4,494–4,585] | 11,690 [11,656–12,287] | 38,189 [37,992–38,932] | 12,220 [12,126–12,259] | 105,701 [105,064–108,353] | 144,697 [143,777–147,434] | 5,302 [5,290–5,322] | 34,861 [34,706–35,029] |
| new messages rows | 6,123 [6,009–6,302] | 4,715 [4,615–4,940] | 793 [677–807] | 4,582 [4,494–4,585] | 11,690 [11,656–12,287] | 38,189 [37,992–38,932] | 12,220 [12,126–12,259] | 105,701 [105,064–108,353] | 144,697 [143,777–147,434] | 5,302 [5,290–5,322] | 34,861 [34,706–35,029] |
| …with the 'bench write' rich text body | 6,123 [6,009–6,302] | 4,715 [4,615–4,940] | 793 [677–807] | 4,582 [4,494–4,585] | 11,690 [11,656–12,287] | 38,189 [37,992–38,932] | 12,220 [12,126–12,259] | 105,701 [105,064–108,353] | 144,697 [143,777–147,434] | 5,302 [5,290–5,322] | 34,861 [34,706–35,029] |
| …in message_search_index (MATCH) | 6,123 [6,009–6,302] | 4,715 [4,615–4,940] | 793 [677–807] | 4,582 [4,494–4,585] | 11,690 [11,656–12,287] | 38,189 [37,992–38,932] | 12,220 [12,126–12,259] | 105,701 [105,064–108,353] | 144,697 [143,777–147,434] | 5,302 [5,290–5,322] | 34,861 [34,706–35,029] |
| integrity_check | ok | ok | ok | ok | ok | ok | ok | ok | ok | ok | ok |

### Action Cable fan-out (one room; chatter.js subscriptions per client)

| Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 100 clients: subscribed | 100 [100–100] | 100 [100–100] | 100 [100–100] | 100 [100–100] | 100 [100–100] | 100 [100–100] | 100 [100–100] | 100 [100–100] | 100 [100–100] | 100 [100–100] | 100 [100–100] |
| 100 clients: connect+subscribe all (s) | 0.27 [0.27–0.27] | 0.26 [0.26–0.27] | 0.34 [0.29–0.39] | 0.30 [0.29–0.30] | 0.19 [0.19–0.28] | 0.47 [0.06–0.64] | 0.06 [0.06–0.06] | 0.06 [0.06–0.07] | 0.07 [0.06–0.07] | 0.07 [0.07–0.07] | 0.07 [0.07–0.07] |
| 100 clients: paced messages delivered to all | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] |
| 100 clients: paced post→one client p50 ms | 37.7 [35.1–38.8] | 33.2 [31.8–33.7] | 101 [81–193] | 34.8 [33.0–37.5] | 23.3 [21.8–24.0] | 14.5 [14.0–22.3] | 12.6 [11.4–12.8] | 7.72 [7.48–7.86] | 5.99 [5.75–6.08] | 45.8 [45.5–46.6] | 16.2 [15.7–16.3] |
| 100 clients: paced post→all clients p50 ms | 47.3 [46.3–48.6] | 53.2 [51.6–54.9] | 101 [81–193] | 37.8 [36.5–39.8] | 26.8 [26.2–28.2] | 21.3 [20.4–33.1] | 14.3 [12.7–14.4] | 9.68 [8.89–9.69] | 7.32 [7.22–7.51] | 46.3 [46.2–47.0] | 17.6 [17.3–18.1] |
| 100 clients: paced post→all clients p99 ms | 76.4 [68.8–84.6] | 79.7 [77.1–89.0] | 1,370 [1,217–1,913] | 53.9 [52.9–65.7] | 40.3 [36.5–52.9] | 33.0 [32.2–45.2] | 18.6 [18.6–23.1] | 12.4 [10.9–20.8] | 12.7 [9.1–22.2] | 51.5 [50.8–52.7] | 21.7 [20.4–22.2] |
| 100 clients: max sustained msgs/s (delivered to all) | 84.9 [83.1–85.5] | 0.40 [0.40–0.50] | 8.90 [6.60–12.70] | 49.0 [48.9–49.5] | 51.7 [51.6–52.1] | 181 [86–207] | 316 [313–317] | 2,060 [2,032–2,069] | 3,215 [3,200–3,230] | 224 [222–225] | 981 [967–986] |
| 100 clients: deliveries/s (client×message) | 8,493 [8,310–8,546] | 67.0 [64.0–69.0] | 891 [658–1,270] | 4,899 [4,893–4,953] | 5,170 [5,159–5,206] | 18,140 [8,963–20,721] | 31,639 [31,287–31,666] | 205,977 [203,203–206,899] | 321,506 [319,958–322,953] | 22,448 [22,214–22,502] | 98,140 [96,736–98,559] |
| 100 clients: saturated post→all p50 ms | 61.6 [60.4–62.0] | 225 [217–252] | 191 [172–238] | 18,842 [18,727–19,153] | 34,996 [34,865–35,160] | 3,820 [1,966–35,521] | 10.4 [10.3–10.5] | 3.38 [3.37–3.38] | 1.55 [1.55–1.57] | 18.1 [18.0–18.3] | 5.20 [5.18–5.24] |
| 100 clients: saturated POST p50 ms | 43.6 [38.0–45.1] | 11.1 [10.2–11.9] | 63.8 [35.7–68.0] | 24.1 [23.9–24.4] | 6.29 [6.24–6.36] | 3.74 [1.05–9.25] | 12.0 [12.0–12.1] | 1.59 [1.58–1.60] | 1.10 [1.10–1.11] | 17.0 [16.8–17.0] | 3.40 [3.40–3.46] |
| 1000 clients: subscribed | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] | 1,000 [1,000–1,000] |
| 1000 clients: connect+subscribe all (s) | 1.32 [1.31–1.35] | 2.75 [2.59–2.87] | 20.1 [20.0–20.2] | 16.7 [16.6–16.9] | 16.6 [16.6–16.7] | 1.47 [1.43–1.48] | 0.44 [0.44–0.53] | 0.16 [0.13–0.17] | 0.11 [0.11–0.11] | 0.23 [0.23–0.23] | 0.21 [0.21–0.23] |
| 1000 clients: paced messages delivered to all | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] | 30.0 [30.0–30.0] |
| 1000 clients: paced post→one client p50 ms | 49.2 [48.5–50.6] | 2,599 [2,396–2,843] | 462 [403–469] | 286 [244–296] | 262 [241–291] | 27.4 [26.4–27.4] | 20.4 [20.2–21.2] | 19.6 [18.8–20.1] | 15.2 [14.8–15.8] | 55.8 [55.8–56.2] | 34.2 [33.8–34.4] |
| 1000 clients: paced post→all clients p50 ms | 98.1 [92.9–100.3] | 3,453 [2,947–3,768] | 499 [438–502] | 319 [291–338] | 303 [289–327] | 46.3 [43.6–47.1] | 28.1 [27.0–29.2] | 27.2 [25.7–27.7] | 23.0 [22.1–23.9] | 59.6 [58.4–61.6] | 41.0 [40.3–41.3] |
| 1000 clients: paced post→all clients p99 ms | 151 [128–162] | 6,730 [6,083–7,193] | 805 [734–879] | 383 [331–403] | 360 [334–405] | 75.7 [56.9–82.7] | 38.8 [36.4–38.9] | 38.6 [35.9–39.5] | 28.9 [27.1–30.4] | 69.0 [68.9–76.3] | 51.0 [48.3–66.8] |
| 1000 clients: max sustained msgs/s (delivered to all) | 11.7 [11.3–11.9] | 0.10 [0.10–0.10] | 4.40 [4.40–4.50] | 5.00 [4.90–5.00] | 5.00 [5.00–5.10] | 23.2 [22.9–23.5] | 74.3 [74.1–76.0] | 304 [302–305] | 570 [565–570] | 110 [109–110] | 172 [170–172] |
| 1000 clients: deliveries/s (client×message) | 11,744 [11,319–11,883] | 121 [118–130] | 4,450 [4,439–4,466] | 4,957 [4,918–4,963] | 5,030 [5,022–5,054] | 23,152 [22,876–23,539] | 74,310 [74,064–75,985] | 304,496 [302,213–305,015] | 569,602 [564,683–569,910] | 109,512 [108,710–110,464] | 171,632 [169,493–171,825] |
| 1000 clients: saturated post→all p50 ms | 1,524 [1,261–1,823] | 519 [501–684] | 33,128 [32,850–34,472] | 37,880 [37,814–38,306] | 38,437 [38,437–38,470] | 962 [677–1,917] | 47.6 [47.1–47.8] | 24.5 [23.9–24.9] | 18.3 [17.8–18.3] | 1,098 [862–1,312] | 4,223 [3,746–4,633] |
| 1000 clients: saturated POST p50 ms | 147 [78–155] | 11.2 [8.4–14.6] | 31.3 [31.1–32.1] | 24.8 [24.4–25.8] | 6.40 [6.31–6.70] | 61.4 [53.1–96.6] | 53.8 [52.9–54.0] | 11.6 [11.6–11.6] | 6.45 [6.32–6.56] | 32.2 [31.7–32.4] | 2.65 [1.98–2.75] |

### Upload + thumbnail (black_hole.jpg, 505 KB)

loadgen's "then GET" follows the response's first <img>, which is the author's avatar; the thumbnail
check is one more, untimed upload whose own <img class="message__attachment"> must return an image.

| Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---|---|---|---|---|---|---|---|---|---|---|
| POST with attachment (ms) | 80.1 [65.3–83.4] | 63.1 [62.9–65.5] | 116 [112–164] | 71.3 [70.5–72.9] | 56.2 [55.2–56.6] | 33.2 [33.2–34.2] | 91.9 [91.1–94.5] | 31.4 [31.0–33.5] | 35.5 [33.3–37.3] | 49.5 [49.3–50.9] | 34.3 [34.0–36.0] |
| then GET first <img> → 200 (ms) | 0.30 [0.30–0.30] | 2.40 [2.30–2.60] | 7.80 [7.60–8.10] | 7.90 [7.90–8.00] | 1.50 [1.40–1.60] | 1.10 [1.00–1.20] | 0.30 [0.20–0.30] | 0.20 [0.20–0.20] | 0.20 [0.20–0.20] | 13.3 [12.8–13.4] | 1.00 [0.90–1.20] |
| POST → first <img> served (ms) | 80.4 [65.6–83.7] | 65.4 [65.3–67.9] | 123 [120–176] | 79.3 [78.4–80.8] | 57.5 [56.6–58.1] | 34.4 [34.2–35.2] | 92.2 [91.3–94.8] | 31.7 [31.2–33.7] | 35.6 [33.4–37.5] | 62.5 [62.3–64.1] | 35.7 [34.9–37.0] |
| thumbnail check: POST → thumbnail bytes (ms) | 69.8 [68.3–77.2] | 101 [80–103] | 123 [122–125] | 83.7 [79.8–85.6] | 60.9 [58.9–63.7] | 34.0 [33.1–35.4] | 94.8 [93.0–95.1] | 33.8 [32.5–33.9] | 35.0 [33.2–35.6] | 64.8 [61.6–67.8] | 40.0 [37.9–41.1] |
| thumbnail check: thumbnail size (bytes) | 84,296 [84,296–84,296] | 84,296 [84,296–84,296] | 75,349 [75,349–75,349] | 75,289 [75,289–75,289] | 75,289 [75,289–75,289] | 93,976 [93,976–93,976] | 84,296 [84,296–84,296] | 84,296 [84,296–84,296] | 84,296 [84,296–84,296] | 84,296 [84,296–84,296] | 84,296 [84,296–84,296] |

### Memory during cable fan-out, by process (MB, peak within the phase)

App process: Rails' Puma master and workers (Action Cable runs in them); Django's Uvicorn processes (HTTP, Cable
and jobs); Laravel's PHP-FPM master and children; Laravel Octane's and Symfony's FrankenPHP; Express's Node
cluster; Elixir's BEAM; the Go and Rust binaries. Serving: everything that takes part in a cable delivery:
Rails + Redis + Thruster; Django (its Redis sidecar is outside the container); Laravel + nginx + the Workerman
cable process; Laravel Octane and Symfony + their cable process; Elixir + Thruster + Redis. Pss counts pages
shared between forked workers once; RssAnon counts them in every process.

| Metric | Rails | Django | Laravel | Laravel FrankenPHP classic | Laravel Octane | Express | Elixir | Go | Rust | Symfony classic | Symfony |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 100 clients, all subscribed, idle: app process Pss | 598 [598–614] | 815 [756–820] | 68.7 [65.3–68.7] | – | 149 [149–150] | 2,120 [1,964–2,247] | 229 [214–231] | 136 [130–138] | 117 [116–118] | 178 [178–180] | 285 [276–291] |
| 100 clients, all subscribed, idle: app process RssAnon | 765 [761–778] | 793 [742–806] | 73.9 [73.7–73.9] | – | 97.1 [96.8–97.3] | 2,096 [1,938–2,227] | 196 [181–198] | 125 [118–127] | 108 [106–108] | 75.3 [73.8–75.5] | 102 [94–110] |
| 100 clients, all subscribed, idle: serving processes Pss | 634 [634–650] | 815 [756–820] | 94.7 [89.4–95.7] | – | 164 [163–164] | 2,120 [1,964–2,247] | 284 [269–286] | 136 [130–138] | 117 [116–118] | 202 [202–204] | 309 [300–315] |
| 100 clients, all subscribed, idle: whole container Pss | 931 [913–944] | 823 [763–827] | 151 [146–152] | 203 [202–203] | 226 [226–227] | 2,120 [1,964–2,247] | 286 [271–288] | 136 [130–138] | 117 [116–118] | 235 [234–236] | 341 [332–347] |
| 100 clients, saturated fan-out: app process Pss | 724 [720–742] | 819 [760–824] | 68.8 [65.6–68.8] | – | 156 [156–160] | 2,121 [2,019–2,250] | 352 [345–361] | 136 [130–138] | 114 [113–117] | 210 [208–213] | 291 [284–292] |
| 100 clients, saturated fan-out: app process RssAnon | 888 [883–906] | 797 [746–810] | 74.0 [73.9–74.0] | – | 106 [105–108] | 2,098 [1,992–2,229] | 319 [312–328] | 125 [120–127] | 104 [104–108] | 78.5 [76.7–79.3] | 108 [102–111] |
| 100 clients, saturated fan-out: serving processes Pss | 770 [768–790] | 819 [760–824] | 95.0 [89.8–95.9] | – | 172 [171–174] | 2,121 [2,019–2,250] | 428 [420–437] | 136 [130–138] | 114 [113–117] | 235 [233–238] | 319 [311–320] |
| 100 clients, saturated fan-out: whole container Pss | 1,067 [1,065–1,067] | 826 [768–831] | 152 [146–152] | 222 [219–222] | 310 [252–315] | 2,121 [2,019–2,250] | 430 [422–438] | 136 [130–138] | 114 [113–117] | 267 [265–271] | 350 [343–350] |
| 1000 clients, all subscribed, idle: app process Pss | 755 [743–766] | 831 [777–834] | 65.5 [65.0–68.7] | – | 265 [263–268] | 2,649 [2,455–2,787] | 391 [374–398] | 152 [146–154] | 120 [115–120] | 326 [317–329] | 372 [366–375] |
| 1000 clients, all subscribed, idle: app process RssAnon | 918 [905–929] | 809 [763–823] | 74.0 [73.9–74.0] | – | 219 [215–220] | 2,626 [2,437–2,767] | 358 [341–364] | 142 [134–143] | 111 [108–112] | 191 [184–195] | 190 [187–195] |
| 1000 clients, all subscribed, idle: serving processes Pss | 846 [836–858] | 831 [777–834] | 115 [114–121] | – | 280 [278–283] | 2,649 [2,455–2,787] | 531 [516–538] | 152 [146–154] | 120 [115–120] | 363 [354–366] | 409 [403–412] |
| 1000 clients, all subscribed, idle: whole container Pss | 1,136 [1,134–1,142] | 839 [784–841] | 180 [176–186] | 339 [329–342] | 430 [420–438] | 2,649 [2,455–2,787] | 533 [518–540] | 152 [146–154] | 120 [115–120] | 396 [386–397] | 441 [434–444] |
| 1000 clients, saturated fan-out: app process Pss | 946 [915–952] | 863 [827–900] | 65.6 [65.0–68.8] | – | 336 [317–346] | 2,649 [2,469–2,786] | 411 [393–423] | 200 [198–204] | 120 [115–120] | 382 [365–388] | 435 [435–438] |
| 1000 clients, saturated fan-out: app process RssAnon | 1,109 [1,075–1,113] | 855 [814–888] | 74.0 [74.0–74.1] | – | 286 [270–297] | 2,626 [2,450–2,768] | 377 [360–389] | 189 [188–193] | 110 [107–112] | 234 [215–237] | 256 [254–258] |
| 1000 clients, saturated fan-out: serving processes Pss | 1,048 [1,016–1,052] | 863 [827–900] | 115 [114–121] | – | 351 [332–361] | 2,649 [2,469–2,786] | 593 [576–601] | 200 [198–204] | 120 [115–120] | 432 [414–438] | 560 [557–570] |
| 1000 clients, saturated fan-out: whole container Pss | 1,327 [1,313–1,348] | 870 [834–908] | 181 [177–187] | 362 [361–377] | 520 [478–533] | 2,649 [2,469–2,786] | 595 [573–600] | 200 [198–204] | 120 [115–120] | 458 [444–467] | 582 [572–582] |
