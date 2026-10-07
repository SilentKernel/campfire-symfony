```
date: 2026-10-06T20:49:03+00:00
host: Apple M3 Pro (6P+6E cores), macOS 26.7.1; Docker VM: OrbStack, 12 vCPUs, 16819298304 B kernel 7.0.14-orbstack-00380-ga7e0a2dc9535
host power: AC Power, lowpowermode 0
server cpus: 0-3 (nproc 4); loadgen cpus: 4-7; harness cpus: 8-11; network: host; port: 4390
workloads: suites [http cable upload]; http 8s at c=[1 16 64] (2s warm-up at c=4); cable clients [100 1000], 15s saturation with 4 posters; upload reps 5
load wait: LOAD_MAX=1.5, LOAD_WAIT_SECS=900; write check settle: 10s; containers running before the run: 1
user agent: (none)
work dir: /opt/campfire-bench/work (overlay)
harness: campfire-symfony bench/run @ 7dcc719 (1 changed files in bench/); once-campfire-rust @ 64f8635 (loadgen, seed)
seed: 50a76ba5a2f63b93 production.sqlite3; loadgen: dbb998a3ba2cdf13
laravel-frankenphp-classic image: once-campfire-laravel-frankenphp:bench linux/arm64 sha256:8f2db872fec0e77c17b0eb585aca576ddfd79b3c5889e2555ad6bba53928453e revision none(label-inherited-from-FrankenPHP-233f793) source https://github.com/php/frankenphp created 2026-10-06T10:01:32.672343852+02:00 digests once-campfire-laravel-frankenphp@sha256:8f2db872fec0e77c17b0eb585aca576ddfd79b3c5889e2555ad6bba53928453e
laravel-frankenphp-classic process model: FrankenPHP classic mode (no worker), PHP_THREADS = 2×CPUs; bin/cable on 127.0.0.1:4391, queue:work; user 0:0
laravel-frankenphp image: once-campfire-laravel-frankenphp:bench linux/arm64 sha256:8f2db872fec0e77c17b0eb585aca576ddfd79b3c5889e2555ad6bba53928453e revision none(label-inherited-from-FrankenPHP-233f793) source https://github.com/php/frankenphp created 2026-10-06T10:01:32.672343852+02:00 digests once-campfire-laravel-frankenphp@sha256:8f2db872fec0e77c17b0eb585aca576ddfd79b3c5889e2555ad6bba53928453e
laravel-frankenphp process model: Octane + FrankenPHP worker mode, PHP_WORKERS = 2×CPUs; bin/cable on 127.0.0.1:4391, queue:work; user 0:0
symfony-classic image: campfire-symfony:app linux/arm64 sha256:aeabf684ed8d7fe95489ad715059c1de31535ff3c6ba9b4dc5f708b73e03a56f revision none(label-inherited-from-FrankenPHP-233f793) source  created 2026-10-06T09:13:32.347124452+02:00 digests campfire-symfony@sha256:aeabf684ed8d7fe95489ad715059c1de31535ff3c6ba9b4dc5f708b73e03a56f
symfony-classic process model: FrankenPHP classic mode (no worker), PHP_THREADS = 2×CPUs; campfire:cable on 127.0.0.1:4391, messenger:consume; user 0:0
symfony image: campfire-symfony:app linux/arm64 sha256:aeabf684ed8d7fe95489ad715059c1de31535ff3c6ba9b4dc5f708b73e03a56f revision none(label-inherited-from-FrankenPHP-233f793) source  created 2026-10-06T09:13:32.347124452+02:00 digests campfire-symfony@sha256:aeabf684ed8d7fe95489ad715059c1de31535ff3c6ba9b4dc5f708b73e03a56f
symfony process model: image defaults: FrankenPHP worker mode, campfire:cable on 127.0.0.1:4391, messenger:consume; user 0:0
```

Reps: Laravel FrankenPHP classic 5, Laravel Octane 5, Symfony classic 5, Symfony 5. Cells: median [min–max] across reps.

### Validation

Each rep must sign in, find the CSRF token, stream sources and stylesheet, get only 2xx/3xx with no transport
errors, persist every acknowledged post (a new message with its rich text body, in the FTS index; integrity_check ok),
deliver every cable message to every client, and serve the uploaded image's thumbnail.

| App | Rep | Result |
|---|---|---|
| Laravel FrankenPHP classic | 1 | **FAIL**: cable 1000 clients: saturated 370/2346 messages reached every client |
| Laravel FrankenPHP classic | 2 | **FAIL**: cable 1000 clients: saturated 371/2394 messages reached every client |
| Laravel FrankenPHP classic | 3 | **FAIL**: cable 1000 clients: saturated 372/2365 messages reached every client |
| Laravel FrankenPHP classic | 4 | **FAIL**: cable 1000 clients: saturated 374/2320 messages reached every client |
| Laravel FrankenPHP classic | 5 | **FAIL**: cable 1000 clients: saturated 375/2335 messages reached every client |
| Laravel Octane | 1 | **FAIL**: cable 100 clients: saturated 3860/7411 messages reached every client; cable 1000 clients: saturated 374/7203 messages reached every client |
| Laravel Octane | 2 | **FAIL**: cable 100 clients: saturated 3838/7347 messages reached every client; cable 1000 clients: saturated 373/7195 messages reached every client |
| Laravel Octane | 3 | **FAIL**: cable 100 clients: saturated 3912/7343 messages reached every client; cable 1000 clients: saturated 376/7283 messages reached every client |
| Laravel Octane | 4 | **FAIL**: cable 100 clients: saturated 3918/7466 messages reached every client; cable 1000 clients: saturated 378/7199 messages reached every client |
| Laravel Octane | 5 | **FAIL**: cable 100 clients: saturated 3877/7312 messages reached every client; cable 1000 clients: saturated 376/7232 messages reached every client |
| Symfony classic | 1 | pass |
| Symfony classic | 2 | pass |
| Symfony classic | 3 | pass |
| Symfony classic | 4 | pass |
| Symfony classic | 5 | pass |
| Symfony | 1 | pass |
| Symfony | 2 | pass |
| Symfony | 3 | pass |
| Symfony | 4 | pass |
| Symfony | 5 | pass |

### HTTP throughput, 16 concurrent clients (req/s, median)

| Route | Laravel FrankenPHP classic | Laravel Octane | Symfony classic | Symfony |
|---|---:|---:|---:|---:|
| Room page | 120 | 178 | 174 | 711 |
| Messages page | 137 | 222 | 350 | 1,627 |
| Sidebar | 318 | 1,698 | 217 | 2,674 |
| Search | 211 | 528 | 301 | 1,499 |
| Post a message | 225 | 599 | 261 | 1,655 |
| /up | 1,126 | 4,612 | 709 | 11,033 |

### What one request returns (untimed GET per rep, same cookie and Accept-Encoding as loadgen)

Messages shown, all elements (start tags), decoded HTML, gzip wire size, visible text, and the hidden CSRF inputs
with their distinct values. The work behind a request is only comparable between apps that show the same messages
with comparable markup; "window differs" marks message ids (in order) unlike the first app's. A CSRF token masked
per form (Rails, Symfony: one distinct value per form) is incompressible, so those pages are about twice as big
on the wire as pages that repeat one token, for the same HTML.

| Page | Metric | Laravel FrankenPHP classic | Laravel Octane | Symfony classic | Symfony |
|---|---|---:|---:|---:|---:|
| Room page | messages | 40 | 40 | 40 | 40 |
| Room page | elements | 4,027 | 4,027 | 4,000 | 4,000 |
| Room page | decoded KB | 390 | 390 | 452 | 452 |
| Room page | gzip KB | 19.6 | 19.6 | 44.0 | 44.0 |
| Room page | visible text KB | 24.9 | 24.9 | 17.6 | 17.6 |
| Room page | CSRF inputs (distinct values) | 644 (1) | 644 (1) | 323 (323) | 323 (323) |
| Messages page | messages | 40 | 40 | 40 | 40 |
| Messages page | elements | 3,880 | 3,880 | 3,720 | 3,720 |
| Messages page | decoded KB | 371 | 371 | 421 | 421 |
| Messages page | gzip KB | 13.0 | 12.9 | 35.1 | 35.1 |
| Messages page | visible text KB | 14.8 | 14.8 | 8.6 | 8.6 |
| Messages page | CSRF inputs (distinct values) | 640 (1) | 640 (1) | 320 (320) | 320 (320) |
| Search | messages | 13 | 13 | 13 | 13 |
| Search | elements | 1,338 | 1,338 | 1,420 | 1,420 |
| Search | decoded KB | 133 | 133 | 161 | 161 |
| Search | gzip KB | 8.0 | 8.0 | 17.3 | 17.3 |
| Search | visible text KB | 11.9 | 11.9 | 10.0 | 10.0 |
| Search | CSRF inputs (distinct values) | 208 (1) | 208 (1) | 107 (107) | 107 (107) |
| Sidebar | messages | 0 | 0 | 0 | 0 |
| Sidebar | elements | 22 | 22 | 248 | 248 |
| Sidebar | decoded KB | 3 | 3 | 31 | 31 |
| Sidebar | gzip KB | 0.8 | 0.8 | 6.2 | 6.2 |
| Sidebar | visible text KB | 0.2 | 0.2 | 7.6 | 7.6 |
| Sidebar | CSRF inputs (distinct values) | 0 (0) | 0 (0) | 3 (3) | 3 (3) |

### Laravel vs Symfony (same FrankenPHP runtime)

Both on FrankenPHP with PHP 8.4, the same thread counts and the same SQLite; classic = no worker (the framework
boots on every request), worker = booted once per thread (Laravel Octane / Symfony runtime). Medians; S/L > 1 means
Symfony does better (more req/s or msgs/s, fewer ms, bytes or MB). HTTP at c=16.

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

Do the two apps render equivalent pages? (first rep of each; the throughput ratios above only compare like
work if they do)

| Mode | Page | Same message window | Messages L / S | Elements L / S | Decoded KB L / S | Visible text KB L / S | gzip KB L / S | CSRF inputs (distinct) L / S | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| classic | Room page | yes | 40 / 40 | 4,027 / 4,000 | 390 / 452 | 24.9 / 17.6 | 19.6 / 44.0 | 644 (1) / 323 (323) | equivalent |
| classic | Messages page | yes | 40 / 40 | 3,880 / 3,720 | 371 / 421 | 14.8 / 8.6 | 13.0 / 35.1 | 640 (1) / 320 (320) | equivalent |
| classic | Search | yes | 13 / 13 | 1,338 / 1,420 | 133 / 161 | 11.9 / 10.0 | 8.0 / 17.3 | 208 (1) / 107 (107) | equivalent |
| classic | Sidebar | yes | 0 / 0 | 22 / 248 | 3 / 31 | 0.2 / 7.6 | 0.8 / 6.2 | 0 (0) / 3 (3) | **different**: elements ×0.09, HTML size ×0.09 |
| worker | Room page | yes | 40 / 40 | 4,027 / 4,000 | 390 / 452 | 24.9 / 17.6 | 19.6 / 44.0 | 644 (1) / 323 (323) | equivalent |
| worker | Messages page | yes | 40 / 40 | 3,880 / 3,720 | 371 / 421 | 14.8 / 8.6 | 12.9 / 35.1 | 640 (1) / 320 (320) | equivalent |
| worker | Search | yes | 13 / 13 | 1,338 / 1,420 | 133 / 161 | 11.9 / 10.0 | 8.0 / 17.3 | 208 (1) / 107 (107) | equivalent |
| worker | Sidebar | yes | 0 / 0 | 22 / 248 | 3 / 31 | 0.2 / 7.6 | 0.8 / 6.2 | 0 (0) / 3 (3) | **different**: elements ×0.09, HTML size ×0.09 |

### Startup and memory

| Metric | Laravel FrankenPHP classic | Laravel Octane | Symfony classic | Symfony |
|---|---|---|---|---|
| cold start: docker run → /up 200 (ms) | 951 [822–991] | 902 [879–954] | 925 [751–934] | 752 [707–854] |
| idle memory.current (MB) | 168 [158–224] | 256 [196–267] | 192 [189–227] | 230 [222–231] |
| idle anon (MB) | 83.0 [83.0–83.0] | 118 [118–120] | 64.0 [64.0–64.0] | 67.0 [67.0–68.0] |
| peak memory.current under load (MB) | 508 [493–522] | 751 [730–798] | 780 [760–804] | 870 [849–890] |
| peak anon under load (MB) | 315 [311–345] | 488 [461–510] | 468 [455–495] | 525 [494–531] |

### HTTP (signed in as david; keep-alive; c = concurrent connections)

| Metric | Laravel FrankenPHP classic | Laravel Octane | Symfony classic | Symfony |
|---|---|---|---|---|
| room_show c=1 req/s | 32.8 [32.6–33.2] | 46.7 [46.3–47.1] | 48.8 [48.1–48.9] | 209 [206–210] |
| room_show c=1 p50 ms | 30.2 [30.0–30.5] | 21.5 [21.1–21.6] | 20.4 [20.4–20.6] | 4.70 [4.69–4.78] |
| room_show c=1 p99 ms | 32.3 [31.4–32.9] | 22.6 [22.2–25.6] | 21.6 [21.2–24.7] | 5.20 [5.11–5.33] |
| room_show c=16 req/s | 120 [119–120] | 178 [172–180] | 174 [164–174] | 711 [710–714] |
| room_show c=16 p50 ms | 133 [133–134] | 92.6 [92.3–94.5] | 91.6 [91.5–94.2] | 22.2 [22.2–22.2] |
| room_show c=16 p99 ms | 190 [171–196] | 127 [127–130] | 127 [123–223] | 36.6 [34.1–38.0] |
| room_show c=64 req/s | 119 [119–120] | 210 [210–210] | 174 [168–174] | 717 [717–719] |
| room_show c=64 p50 ms | 534 [533–535] | 304 [303–304] | 367 [366–379] | 88.6 [88.5–89.0] |
| room_show c=64 p99 ms | 589 [583–591] | 333 [330–339] | 403 [389–415] | 103 [102–104] |
| messages_page c=1 req/s | 36.8 [36.4–36.9] | 60.4 [60.0–60.7] | 97.9 [92.3–99.2] | 537 [532–542] |
| messages_page c=1 p50 ms | 27.1 [27.0–27.5] | 16.4 [16.4–16.5] | 10.1 [10.0–10.6] | 1.84 [1.80–1.86] |
| messages_page c=1 p99 ms | 28.9 [28.1–30.2] | 19.0 [17.0–21.7] | 10.9 [10.6–16.7] | 2.06 [2.03–2.08] |
| messages_page c=16 req/s | 137 [136–137] | 222 [221–222] | 350 [348–351] | 1,627 [1,606–1,656] |
| messages_page c=16 p50 ms | 117 [116–118] | 71.7 [71.6–72.0] | 45.6 [45.4–46.0] | 9.42 [9.29–9.50] |
| messages_page c=16 p99 ms | 161 [158–169] | 102 [99–103] | 62.7 [60.2–64.1] | 21.2 [20.9–21.6] |
| messages_page c=64 req/s | 136 [135–137] | 221 [221–222] | 350 [349–350] | 1,731 [1,721–1,739] |
| messages_page c=64 p50 ms | 468 [466–470] | 288 [288–289] | 183 [182–183] | 36.3 [36.2–36.5] |
| messages_page c=64 p99 ms | 517 [502–535] | 318 [317–321] | 198 [197–200] | 59.2 [57.9–59.5] |
| sidebar c=1 req/s | 86.1 [85.1–88.0] | 519 [514–526] | 58.7 [57.4–59.1] | 811 [790–824] |
| sidebar c=1 p50 ms | 11.5 [11.3–11.7] | 1.87 [1.86–1.91] | 16.9 [16.7–17.4] | 1.18 [1.17–1.22] |
| sidebar c=1 p99 ms | 12.3 [11.9–13.1] | 2.15 [2.13–2.20] | 18.0 [17.9–18.8] | 1.40 [1.35–1.41] |
| sidebar c=16 req/s | 318 [316–318] | 1,698 [1,612–1,871] | 217 [216–217] | 2,674 [2,662–2,683] |
| sidebar c=16 p50 ms | 50.2 [50.1–50.2] | 8.30 [8.26–8.39] | 73.6 [73.5–73.7] | 5.46 [5.44–5.48] |
| sidebar c=16 p99 ms | 71.8 [69.3–72.5] | 18.2 [16.7–18.7] | 98.9 [97.0–105.0] | 14.5 [13.8–14.6] |
| sidebar c=64 req/s | 317 [305–318] | 1,832 [1,815–1,838] | 217 [217–217] | 2,869 [2,853–2,890] |
| sidebar c=64 p50 ms | 201 [201–202] | 34.6 [34.4–34.8] | 294 [294–294] | 21.8 [21.6–21.9] |
| sidebar c=64 p99 ms | 221 [218–531] | 45.9 [45.0–47.2] | 319 [316–323] | 37.5 [37.2–39.2] |
| search c=1 req/s | 56.7 [55.9–57.0] | 144 [135–146] | 82.2 [80.8–84.2] | 426 [423–436] |
| search c=1 p50 ms | 17.5 [17.5–17.8] | 6.86 [6.79–6.86] | 12.1 [11.8–12.3] | 2.31 [2.27–2.32] |
| search c=1 p99 ms | 20.0 [18.3–20.9] | 8.26 [7.65–8.35] | 12.9 [12.4–13.6] | 2.59 [2.50–2.72] |
| search c=16 req/s | 211 [210–211] | 528 [510–529] | 301 [301–302] | 1,499 [1,494–1,510] |
| search c=16 p50 ms | 75.6 [75.5–75.9] | 30.1 [30.0–30.2] | 53.0 [53.0–53.1] | 10.4 [10.4–10.5] |
| search c=16 p99 ms | 103 [100–106] | 44.7 [44.2–48.9] | 74.6 [72.3–76.8] | 20.6 [20.1–21.1] |
| search c=64 req/s | 211 [209–212] | 531 [530–536] | 301 [300–301] | 1,523 [1,517–1,530] |
| search c=64 p50 ms | 302 [302–304] | 120 [119–120] | 212 [212–213] | 41.6 [41.4–41.8] |
| search c=64 p99 ms | 330 [327–394] | 134 [133–134] | 233 [230–236] | 55.0 [54.4–57.3] |
| avatar c=1 req/s | 136 [133–138] | 962 [917–969] | 80.3 [79.5–82.7] | 1,482 [1,462–1,505] |
| avatar c=1 p50 ms | 7.37 [7.21–7.49] | 1.00 [0.99–1.00] | 12.5 [11.9–12.6] | 0.64 [0.62–0.64] |
| avatar c=1 p99 ms | 8.73 [8.38–9.47] | 1.66 [1.56–1.80] | 13.4 [12.8–13.9] | 0.80 [0.78–0.83] |
| avatar c=16 req/s | 503 [502–503] | 3,132 [2,656–3,216] | 304 [303–304] | 4,761 [4,742–4,877] |
| avatar c=16 p50 ms | 31.7 [31.6–31.9] | 4.59 [4.55–4.64] | 52.6 [52.6–52.7] | 2.84 [2.78–2.88] |
| avatar c=16 p99 ms | 44.6 [44.0–45.5] | 12.6 [12.2–12.9] | 73.7 [72.2–74.8] | 9.44 [9.25–9.71] |
| avatar c=64 req/s | 503 [465–504] | 2,580 [2,287–3,308] | 304 [303–304] | 5,263 [5,195–5,295] |
| avatar c=64 p50 ms | 127 [127–127] | 19.1 [18.9–19.1] | 210 [210–210] | 11.4 [11.3–11.5] |
| avatar c=64 p99 ms | 141 [140–736] | 32.3 [30.5–34.5] | 230 [227–232] | 25.0 [24.7–26.1] |
| static_css c=1 req/s | 12,739 [12,422–12,826] | 12,293 [11,940–12,642] | 12,888 [12,828–13,043] | 12,287 [11,963–12,868] |
| static_css c=1 p50 ms | 0.07 [0.07–0.07] | 0.08 [0.07–0.08] | 0.07 [0.07–0.07] | 0.08 [0.07–0.08] |
| static_css c=1 p99 ms | 0.10 [0.10–0.11] | 0.10 [0.10–0.10] | 0.10 [0.10–0.10] | 0.10 [0.10–0.10] |
| static_css c=16 req/s | 68,268 [67,762–69,215] | 68,141 [66,978–69,959] | 70,239 [69,189–73,426] | 70,923 [70,161–72,236] |
| static_css c=16 p50 ms | 0.18 [0.18–0.18] | 0.18 [0.18–0.18] | 0.17 [0.17–0.18] | 0.18 [0.17–0.18] |
| static_css c=16 p99 ms | 1.31 [1.29–1.38] | 1.27 [1.22–1.43] | 1.27 [1.05–1.31] | 1.19 [1.06–1.24] |
| static_css c=64 req/s | 71,796 [71,579–74,828] | 72,331 [71,398–75,016] | 74,259 [73,960–76,250] | 73,990 [72,811–75,961] |
| static_css c=64 p50 ms | 0.64 [0.63–0.65] | 0.64 [0.63–0.65] | 0.63 [0.62–0.64] | 0.63 [0.62–0.63] |
| static_css c=64 p99 ms | 4.44 [4.20–4.62] | 4.33 [4.05–4.59] | 4.16 [4.08–4.29] | 4.16 [4.06–4.53] |
| up c=1 req/s | 324 [314–329] | 1,439 [1,415–1,446] | 186 [185–193] | 3,253 [3,226–3,391] |
| up c=1 p50 ms | 3.08 [3.01–3.13] | 0.69 [0.68–0.69] | 5.38 [5.16–5.39] | 0.29 [0.27–0.29] |
| up c=1 p99 ms | 3.42 [3.35–3.60] | 0.89 [0.86–0.91] | 5.60 [5.55–5.85] | 0.39 [0.38–0.40] |
| up c=16 req/s | 1,126 [1,058–1,155] | 4,612 [4,582–4,660] | 709 [708–712] | 11,033 [10,976–11,140] |
| up c=16 p50 ms | 13.7 [13.7–13.8] | 3.13 [3.08–3.14] | 22.5 [22.5–22.5] | 1.34 [1.32–1.34] |
| up c=16 p99 ms | 24.4 [23.4–27.0] | 8.59 [8.49–8.80] | 34.0 [32.4–36.2] | 4.05 [4.04–4.22] |
| up c=64 req/s | 1,165 [1,157–1,174] | 4,738 [2,603–4,840] | 712 [711–713] | 11,444 [11,369–11,555] |
| up c=64 p50 ms | 54.7 [54.2–54.8] | 12.9 [12.7–13.0] | 89.7 [89.4–89.9] | 5.35 [5.28–5.36] |
| up c=64 p99 ms | 65.5 [64.1–67.8] | 24.0 [23.6–24.1] | 99.9 [98.9–100.8] | 13.8 [13.8–14.0] |
| post_message c=1 req/s | 63.0 [62.7–63.4] | 237 [233–242] | 70.3 [69.9–72.4] | 668 [668–678] |
| post_message c=1 p50 ms | 15.3 [15.2–15.4] | 3.67 [3.62–3.70] | 14.2 [13.7–14.2] | 1.37 [1.35–1.37] |
| post_message c=1 p99 ms | 28.7 [26.6–29.2] | 17.1 [16.7–18.2] | 15.3 [14.8–16.2] | 5.79 [5.59–6.12] |
| post_message c=16 req/s | 225 [221–229] | 599 [585–611] | 261 [261–262] | 1,655 [1,652–1,673] |
| post_message c=16 p50 ms | 68.3 [66.8–68.9] | 20.6 [19.9–21.2] | 61.0 [60.7–61.0] | 7.92 [7.86–8.06] |
| post_message c=16 p99 ms | 153 [132–165] | 128 [118–129] | 80.8 [79.2–81.9] | 41.5 [39.9–44.0] |
| post_message c=64 req/s | 225 [223–229] | 617 [594–624] | 261 [260–262] | 1,662 [1,630–1,672] |
| post_message c=64 p50 ms | 281 [276–282] | 98.0 [97.1–100.0] | 244 [244–245] | 37.0 [36.8–37.6] |
| post_message c=64 p99 ms | 365 [359–368] | 210 [206–230] | 265 [261–266] | 71.4 [70.0–72.9] |

### HTTP response size (average bytes as received, gzip where offered)

A sanity check that every app returns the full page: very different sizes deserve a look.

| Metric | Laravel FrankenPHP classic | Laravel Octane | Symfony classic | Symfony |
|---|---|---|---|---|
| room_show | 20,032 [20,030–20,034] | 20,031 [20,030–20,033] | 45,042 [45,018–45,100] | 45,044 [45,026–45,100] |
| messages_page | 13,333 [13,222–13,342] | 13,315 [13,179–13,338] | 35,935 [35,918–35,947] | 35,921 [35,909–35,951] |
| sidebar | 817 [817–817] | 817 [817–817] | 6,395 [6,395–6,395] | 6,395 [6,395–6,395] |
| search | 8,215 [8,213–8,217] | 8,215 [8,212–8,217] | 17,716 [17,702–17,719] | 17,716 [17,703–17,723] |
| avatar | 2,930 [2,930–2,930] | 2,930 [2,930–2,930] | 3,364 [3,364–3,364] | 3,364 [3,364–3,364] |
| static_css | 654 [654–654] | 654 [654–654] | 654 [654–654] | 654 [654–654] |
| up | 73.0 [73.0–73.0] | 73.0 [73.0–73.0] | 73.0 [73.0–73.0] | 73.0 [73.0–73.0] |
| post_message | 1,973 [1,971–1,976] | 1,975 [1,971–1,976] | 2,031 [2,030–2,031] | 2,033 [2,033–2,034] |

### HTTP errors / non-2xx-3xx (all reps)

- Laravel FrankenPHP classic: none
- Laravel Octane: none
- Symfony classic: none
- Symfony: none

### Persisted writes (post_message route, warm-up included; checked before the cable suite)

Acknowledged = 2xx/3xx responses. Each must be a new messages row with its rich text body and an FTS entry.

| Metric | Laravel FrankenPHP classic | Laravel Octane | Symfony classic | Symfony |
|---|---|---|---|---|
| acknowledged posts | 4,510 [4,448–4,587] | 11,782 [11,473–12,848] | 5,308 [5,279–5,329] | 34,881 [34,561–34,953] |
| new messages rows | 4,510 [4,448–4,587] | 11,782 [11,473–12,848] | 5,308 [5,279–5,329] | 34,881 [34,561–34,953] |
| …with the 'bench write' rich text body | 4,510 [4,448–4,587] | 11,782 [11,473–12,848] | 5,308 [5,279–5,329] | 34,881 [34,561–34,953] |
| …in message_search_index (MATCH) | 4,510 [4,448–4,587] | 11,782 [11,473–12,848] | 5,308 [5,279–5,329] | 34,881 [34,561–34,953] |
| integrity_check | ok | ok | ok | ok |

### Action Cable fan-out (one room; chatter.js subscriptions per client)

| Metric | Laravel FrankenPHP classic | Laravel Octane | Symfony classic | Symfony |
|---|---|---|---|---|
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

### Upload + thumbnail (black_hole.jpg, 505 KB)

loadgen's "then GET" follows the response's first <img>, which is the author's avatar; the thumbnail
check is one more, untimed upload whose own <img class="message__attachment"> must return an image.

| Metric | Laravel FrankenPHP classic | Laravel Octane | Symfony classic | Symfony |
|---|---|---|---|---|
| POST with attachment (ms) | 69.9 [69.0–70.4] | 57.3 [56.2–58.9] | 49.7 [47.9–51.0] | 34.8 [33.9–36.3] |
| then GET first <img> → 200 (ms) | 7.90 [7.70–8.00] | 1.50 [1.40–1.60] | 13.3 [12.5–13.4] | 1.00 [1.00–1.10] |
| POST → first <img> served (ms) | 77.5 [77.1–78.7] | 58.8 [57.6–60.4] | 63.2 [60.8–65.2] | 35.7 [34.8–37.3] |
| thumbnail check: POST → thumbnail bytes (ms) | 79.5 [78.7–82.3] | 62.0 [60.0–63.1] | 62.8 [61.4–64.2] | 39.7 [37.5–43.9] |
| thumbnail check: thumbnail size (bytes) | 75,289 [75,289–75,289] | 75,289 [75,289–75,289] | 84,296 [84,296–84,296] | 84,296 [84,296–84,296] |

### Memory during cable fan-out, by process (MB, peak within the phase)

App process: Rails' Puma master and workers (Action Cable runs in them); Django's Uvicorn processes (HTTP, Cable
and jobs); Laravel's PHP-FPM master and children; Laravel Octane's and Symfony's FrankenPHP; Express's Node
cluster; Elixir's BEAM; the Go and Rust binaries. Serving: everything that takes part in a cable delivery:
Rails + Redis + Thruster; Django (its Redis sidecar is outside the container); Laravel + nginx + the Workerman
cable process; Laravel Octane and Symfony + their cable process; Elixir + Thruster + Redis. Pss counts pages
shared between forked workers once; RssAnon counts them in every process.

| Metric | Laravel FrankenPHP classic | Laravel Octane | Symfony classic | Symfony |
|---|---|---|---|---|
| 100 clients, all subscribed, idle: app process Pss | – | 151 [150–158] | 177 [175–178] | 280 [279–284] |
| 100 clients, all subscribed, idle: app process RssAnon | – | 98.8 [98.7–103.2] | 74.0 [73.1–74.8] | 98.2 [97.6–103.3] |
| 100 clients, all subscribed, idle: serving processes Pss | – | 166 [165–173] | 201 [199–203] | 304 [303–308] |
| 100 clients, all subscribed, idle: whole container Pss | 201 [200–202] | 229 [228–236] | 233 [231–234] | 337 [336–340] |
| 100 clients, saturated fan-out: app process Pss | – | 157 [156–160] | 210 [205–213] | 286 [280–295] |
| 100 clients, saturated fan-out: app process RssAnon | – | 106 [105–109] | 76.5 [75.1–80.9] | 105 [98–114] |
| 100 clients, saturated fan-out: serving processes Pss | – | 171 [170–174] | 235 [230–238] | 314 [307–323] |
| 100 clients, saturated fan-out: whole container Pss | 218 [211–228] | 248 [242–256] | 266 [261–270] | 345 [338–356] |
| 1000 clients, all subscribed, idle: app process Pss | – | 269 [265–272] | 320 [316–322] | 368 [365–379] |
| 1000 clients, all subscribed, idle: app process RssAnon | – | 222 [218–223] | 185 [183–192] | 190 [184–196] |
| 1000 clients, all subscribed, idle: serving processes Pss | – | 284 [281–286] | 357 [354–358] | 405 [402–416] |
| 1000 clients, all subscribed, idle: whole container Pss | 339 [333–345] | 443 [435–447] | 387 [386–390] | 436 [435–449] |
| 1000 clients, saturated fan-out: app process Pss | – | 331 [325–345] | 364 [360–384] | 442 [426–445] |
| 1000 clients, saturated fan-out: app process RssAnon | – | 283 [274–297] | 216 [215–236] | 263 [246–266] |
| 1000 clients, saturated fan-out: serving processes Pss | – | 346 [340–360] | 414 [410–433] | 564 [548–574] |
| 1000 clients, saturated fan-out: whole container Pss | 353 [351–375] | 508 [494–518] | 444 [437–463] | 586 [579–597] |
