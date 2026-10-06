# Campfire in Symfony

[ONCE Campfire](https://github.com/basecamp/once-campfire) ported to **Symfony 7.4 LTS** on
**PHP 8.4**, served by **FrankenPHP in worker mode**. It is a full port of the Rails app pinned in
`reference/` (`254dd1d`). All controllers, views, channels, jobs and media processing are ported.

It is a drop-in replacement. Point it at an existing Campfire storage volume with the same
`SECRET_KEY_BASE` and nothing needs migrating. Nobody is signed out, and you can switch back to
the Rails image at any time:

- **Database:** the same SQLite file and schema, byte for byte, including `schema_migrations`,
  `ar_internal_metadata` and the FTS5 search index. No tables or columns are added. The job queue
  lives in a separate `jobs.sqlite3`.
- **Files:** the same Active Storage blobs, keys, `files/xx/yy/key` layout, variant records and
  variation digests. Variants are byte-identical with the same libvips.
- **Passwords:** bcrypt `$2a$12$` digests, as `has_secure_password` writes them.
- **Cookies, both ways:** `session_token` (signed), `_campfire_session` (encrypted, with
  `_csrf_token`, flash and `return_to`) and `last_room`. Masked and per-form CSRF tokens, signed
  ids, SGIDs and Turbo stream names. A session started on one runtime keeps working on the other.
- **Action Cable:** `actioncable-v1-json` on `/cable`, with the same channels, stream names and
  payloads.
- **Frontend:** the original Turbo/Stimulus/Lexxy JavaScript and CSS, unchanged. The Twig templates
  reproduce the ERB markup (ids, classes, data attributes).

## Running it

```sh
docker build -t campfire-symfony .

docker run -d --name campfire -p 80:80 -p 443:443 \
  -e SECRET_KEY_BASE=... \
  -e VAPID_PUBLIC_KEY=... -e VAPID_PRIVATE_KEY=... \
  -e TLS_DOMAIN=chat.example.com \
  -v campfire:/rails/storage \
  campfire-symfony
```

Plain HTTP on another port, for a trial or behind your own TLS proxy:

```sh
docker run -d -p 8080:8080 \
  -e SECRET_KEY_BASE="$(openssl rand -hex 64)" -e DISABLE_SSL=true -e HTTP_PORT=8080 \
  -v campfire:/rails/storage campfire-symfony
```

- **Existing installs** must keep their `SECRET_KEY_BASE`, which signs and encrypts every cookie
  and signed id. They must also keep `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY`, or existing push
  subscriptions stop working. Mount the same storage at `/rails/storage`.
- **New VAPID keys** (P-256, URL-safe Base64):
  `docker run --rm campfire-symfony php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'`
- **Storage:** `/rails/storage` holds `db/` (`production.sqlite3`, `jobs.sqlite3`), `files/`
  (uploads), `backups/` (written by the ONCE `pre-backup` hook) and, with `TLS_DOMAIN`, `caddy/`
  (certificates).
- **User:** the image runs as `1000:1000`, like the Rails image. It also runs under any
  `--user uid:gid` (the benchmark uses `0:0`, the smoke test `12345:12345`), as long as that user
  can write to the storage. It never chowns anything.
- **Platforms:** the base images are multi-arch (`linux/amd64`, `linux/arm64`). The image has been
  built and verified on `linux/arm64`.
- **ONCE:** the backup and restore hooks are in `/hooks`.

### Configuration

The Rails image's variables keep their meaning; the rest size this image's own processes.

| Variable | Default | Meaning |
|---|---|---|
| `SECRET_KEY_BASE` | required | Signs and encrypts cookies, signed ids, SGIDs and stream names. Use the Rails app's value. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | empty | Web Push keys. Without them the page has no VAPID key and push is unavailable. |
| `TLS_DOMAIN` | empty | Comma-separated domains for automatic HTTPS (Let's Encrypt, or `ACME_DIRECTORY`). Certificates are kept in `storage/caddy`. |
| `DISABLE_SSL` | empty | Set to serve plain HTTP: no HSTS, no `secure` cookie flag, and requests aren't treated as HTTPS. |
| `HTTP_PORT`, `HTTPS_PORT` | `80`, `443` | Listening ports. |
| `TARGET_PORT` | `HTTP_PORT + 1` | Loopback port of the Action Cable server, which Caddy proxies `/cable` to. |
| `PHP_WORKERS` | 2 × CPUs | FrankenPHP worker threads. |
| `PHP_THREADS` | `1` | Extra non-worker PHP threads. |
| `JOB_CONCURRENCY` | `1` | `messenger:consume` processes. |
| `CADDY_LOG_LEVEL` | `WARN` | Caddy's log level. |
| `APP_VERSION`, `GIT_REVISION` | empty | Sent as `X-Version` / `X-Rev`, as in Rails. |
| `CAMPFIRE_STORAGE_PATH` | `/rails/storage` | Storage root. |

The app logs JSON to stderr (`docker logs`).

### Process model

`bin/start` is the entrypoint. It plays the part of the Rails image's `bin/boot` and Procfile:

1. `bin/console campfire:install` does what `db:prepare` does. It creates `db/` and `files/` and
   loads the schema into an empty database. It refuses to start on a database that is missing a
   Rails migration: boot the Rails image once to migrate it.
2. `frankenphp run`: Caddy plus the PHP worker threads. It does Thruster's job too: TLS, gzip, the
   static files in `public/` and `/assets`, X-Accel-Redirect for blobs, and the `/cable` reverse
   proxy.
3. `bin/console campfire:cable`: the Action Cable server, a Workerman event loop on
   `127.0.0.1:$TARGET_PORT`. The app publishes to it over a unix socket, the way Rails publishes
   through Redis.
4. `bin/console messenger:consume async` × `JOB_CONCURRENCY`: the background jobs (push
   notifications, bot webhooks, banned-content removal, blob analysis and purge). Rails runs
   these on Resque.

`bin/start` restarts the cable server and the job workers whenever they exit. If FrankenPHP exits,
the container exits non-zero. There is no Redis.

See [docs/architecture.md](docs/architecture.md) for the details.

## Development

Requirements: PHP 8.4 with `apcu`, `ffi`, `gmp`, `intl`, `pcntl`, `pdo_sqlite` and `sodium`;
libvips and ffmpeg for media; Composer; Docker for the seed, the reference image and `bin/check`.

```sh
git submodule update --init                     # reference/: the Rails app (a test oracle)
composer install
printf 'APP_ENV=dev\nAPP_DEBUG=1\n' > .env.local  # .env defaults to prod
bin/console campfire:install                    # storage/db/development.sqlite3
symfony serve                                   # or: php -S 127.0.0.1:8000 -t public
```

`symfony serve` serves pages, but not `/cable`. For the full stack (realtime, jobs, Caddy) run
the image with `DISABLE_SSL=true` as shown above.

```sh
php bin/phpunit                  # the test suite (needs the seed: bin/fetch-seed)
vendor/bin/phpstan               # static analysis
vendor/bin/php-cs-fixer fix      # formatting
bin/check                        # all three in the image's dev stage (PHP 8.4 ZTS): authoritative
tests/Container/smoke.sh         # the production image on fresh storage and on a copy of the seed
```

The functional tests run against the **parity seed**: a database and file tree built by the Rails
app, from once-campfire-rust's harness. `bench/bin/runner bench/bin/setup` builds it once, and
`bin/fetch-seed` copies it to `var/seed/default`.

The suite has **3,846 tests** (`php bin/phpunit --list-tests`, data-provider cases counted):

| Suite | Tests | What it covers |
|---|---:|---|
| `Unit/Rails` | 1,501 | Key derivation, message verifier/encryptor, cookie codecs, CSRF, signed ids, GlobalID/SGIDs, Turbo stream names, ActiveSupport JSON and bcrypt, all against golden vectors generated by Rails |
| `Unit/Platform` | 811 | User-agent parsing and the browser gate (Rails vectors), ban addresses, QR codes |
| `Unit/Domain` | 534 | Message and room helpers, pagination, link unfurling and its private-network guard, Ruby integer parsing |
| `Unit/Storage` | 127 | Variations and their digests, variant and preview bytes, analysis, Marcel MIME detection, filenames, signed storage URLs (Rails vectors) |
| other `Unit/*` | 248 | Rich-text corpus, Twig helpers and ERB escaping, Cable protocol, HTTP pipeline, database types, jobs, push, routing, views |
| `Functional/Routing` | 219 | Every route recognized and generated as `bin/rails routes` does (Rails route vectors) |
| other `Functional/*` | 389 | Controllers end to end on a fresh copy of the seed: auth, rooms, messages, search, accounts, bots, Active Storage, database round trips, assets |
| `Integration/Cable` | 17 | The real `campfire:cable` process over WebSockets and its publish socket |

See [docs/development.md](docs/development.md) for the tooling, and [AGENTS.md](AGENTS.md) for the
conventions.

## Verification

Apart from the unit and functional tests, three independent checks compared the production image
with the Rails image (2026-10-05):

- **[Parity harness](docs/internal/parity-report.md):** once-campfire-rust's harness ran on the whole
  screen inventory. That is 5 seeds, 222 states and 956 cells, with server HTML, live DOM,
  accessibility tree, screenshots, network and Action Cable frames compared against Rails.
  - Cable frames matched in 940 of 940 cells, and fragments in 16 of 16.
  - The DOM, accessibility tree and pixels matched everywhere except six findings: two template
    bugs, a missing frame layout and three header details.
  - The six findings were fixed after the run. The report's tables describe the code before those
    fixes.
  - The remaining network differences come from the front proxy (Thruster vs Caddy) and are
    listed below.
- **[Cross-runtime](docs/internal/crossruntime-report.md):** Rails and Symfony ran on one shared
  storage volume, taking turns, at the same time, after a SIGKILL, and from empty storage.
  - Result: **375 checks, 0 failures.**
  - Sessions, CSRF tokens, flash, `return_to`, `last_room` and session transfers carry over in
    both directions.
  - Data written by either app reads back identically on the other: messages, mentions, uploads,
    variants, edits, boosts, rooms, bot posts and search.
  - Rails' `db:migrate:status` stays clean.
  - The schema Symfony creates is byte-identical to Rails'.
- **[End to end](docs/internal/e2e-report.md):** 11 browser scenarios in two real Chromium sessions
  all pass, with the Rails image as the oracle: first run, live messages, typing, mentions,
  uploads, edit/delete/boost, infinite scroll, search, rooms and DMs, admin, sign-out. The
  rendered DOM of two seeded rooms (31 and 131 messages) diffed against Rails with 0 differing
  lines; in All Talk this holds once author names are ignored, since a renamed author is stale in
  cached fragments on Rails too. The oddities found also happen on Rails and are listed in the
  report.

## Benchmarks

The benchmark compares Rails, Django, Laravel and Symfony on one machine (Apple M3 Pro, OrbStack).
Each app gets the same 4 pinned vCPUs, the same seed and the same workloads. The harness and load
generator are once-campfire-rust's, adapted to run four apps. Each app gets 5 repetitions in
alternating order, and the tables give medians with [min–max] ranges. These numbers are not
comparable with the AMD Ryzen numbers in the once-campfire README. Method, configuration and
limitations: [docs/benchmarks.md](docs/benchmarks.md).

<!-- BENCHMARK RESULTS -->

## Known differences

These are the only intended differences from the Rails image. Database, files and cookie formats
are unaffected.

**Front server (Caddy instead of Thruster)**

- Caddy gzips only bodies of 512 bytes or more, and streams them chunked. Thruster gzips
  everything. `Vary: Accept-Encoding` is only on compressed responses.
- Files Caddy serves (`public/`, `/assets`, blobs offloaded with X-Accel-Redirect) carry
  `Accept-Ranges`, `ETag` and `Last-Modified`. Static error pages are
  `text/html; charset=utf-8`, with the same bytes.
- Thruster's response cache and its `X-Cache` header are not reproduced. Its rule of dropping
  `Set-Cookie` from publicly cacheable responses is reproduced, in the app.
- Every response has a `Date` header.
- Digest-stamped `/assets/*` get `Cache-Control: public, immutable, max-age=31536000`. Rails sends
  `public, max-age=2592000` for every public file.
- Asset digests are AssetMapper's 7 characters, not Propshaft's 8 hex characters.

**Action Cable**

- Limits Rails doesn't have: 64 subscriptions per connection, 4 KB identifiers, 1 MB messages,
  256 pending commands. A client whose 8 MB send buffer fills is disconnected, and it reconnects.
- No `permessage-deflate`.
- Banned or deactivated users are refused at connect. Rails only checks that the session exists.
- Re-subscribing with the same identifier doesn't create duplicate streams.
- Broadcasts made while the cable server is down are dropped with a warning. A Redis outage drops
  them in Rails too.
- Each runtime has its own pub/sub. If Rails and Symfony run at the same time on one storage, a
  message posted on one isn't pushed live to sockets on the other. They see it on refresh.

**Rich text** (Action Text sanitizer and renderer, ported onto PHP's HTML5 parser)

- `<` and `>` are escaped inside attribute values before autolinking. This closes a stored XSS in
  `rails_autolink`. `name` attributes are dropped (DOM clobbering). Both match the Rust port.
- Mentions of deleted users render ☒ instead of blanking the message, and are left out in the
  editor. Content attachments nested more than 8 levels deep render empty.
- An SGID mention that can't be decoded at all is left unresolved. Rails raises.
- Checked against Rails on 658 corpus cases (presentation 656, plain text 655, editor 653,
  mentions 658) and 169 of 169 seed messages, byte for byte. The remaining cases are fuzzed,
  malformed markup that lexbor and Gumbo repair differently.

**Media**

- ffmpeg is killed after 60 s and ffprobe after 30 s. Rails has no limit.
- Blob files are written before their row. A failure leaves an orphan file, not an orphan row.
- Blob downloads sent by the app (`send_file`, proxy) carry Rails' default security headers, which
  Rails' live streaming omits.

**Link previews**

- 5 s idle and 10 s total per request (Rails: 60 s). The private-network block list reimplements
  surfguard's default policy, and Symfony's `NoPrivateNetworkHttpClient` checks it again.

**Smaller details**

- Room deletion removes messages in bulk. The end state is the same, and blobs are purged later.
- Messages-page `ETag` values differ (different template digest). 304 behaviour is the same.
- A failed test notification is logged, and the subscription removed, instead of raising.
- `X-Rev` is omitted when `GIT_REVISION` is empty, as Rails omits it when the variable is unset.
- The Symfony firewall is lazy rather than `stateless`, since Symfony cannot make a stateless
  firewall lazy. PHP sessions are off either way.
- Not ported: JSON `wrap_parameters` (controllers read top-level JSON keys) and
  `verify_same_origin_request` (the only JavaScript endpoint skips forgery protection anyway).

Behaviour that looks odd but matches Rails is kept: for example, no suggestions in the "new ping"
picker, a stale author name in cached messages, and `&amp;amp;` in the PWA manifest. See the
[e2e report](docs/internal/e2e-report.md).

## Documentation

- [Architecture](docs/architecture.md): processes, request lifecycle, Rails-to-Symfony mapping,
  directory layout.
- [Rails compatibility](docs/rails-compatibility.md): cookies, CSRF, signed ids, datetime and
  schema formats, Active Storage, switching runtimes, golden vectors.
- [Realtime](docs/realtime.md): the Action Cable server and broadcaster.
- [Development](docs/development.md): setup, tests, seeds, parity tools, adding a feature.
- [Benchmarks](docs/benchmarks.md): method and how to reproduce.

## Credits and licence

MIT, like ONCE Campfire.

- **[ONCE Campfire](https://github.com/basecamp/once-campfire)** (37signals, MIT): the
  specification (`reference/`, pinned at `254dd1d`). The frontend in `assets/` (JavaScript,
  stylesheets, images, sounds and the vendored gem assets) and the ONCE hooks come from it
  unchanged. Provenance is recorded in `assets/PROVENANCE*.md`.
- **[once-campfire-rust](https://github.com/basecamp/once-campfire-rust)** (37signals, MIT): the
  golden vectors in `tests/vectors/`, the parity harness, the load generator, the seeds and the
  benchmark scripts `bench/run` and `bench/report` (adapted). It is also the reference for how
  several Rails behaviours are matched, and for the rich-text corpus.
- **[once-campfire-laravel](https://github.com/basecamp/once-campfire-laravel)** and
  **[once-campfire-django](https://github.com/basecamp/once-campfire-django)**: two ideas. Keeping
  the job queue in its own SQLite file, outside the Rails schema. Serving Action Cable from a
  Workerman process (Laravel).
- Built on Symfony, Doctrine, Twig, [FrankenPHP](https://frankenphp.dev) and Caddy,
  [Workerman](https://github.com/walkor/workerman), PHP 8.4's `Dom\HTMLDocument` (lexbor),
  [php-vips](https://github.com/libvips/php-vips) and libvips, ffmpeg,
  [minishlink/web-push](https://github.com/web-push-libs/web-push-php) and
  [bacon/bacon-qr-code](https://github.com/Bacon/BaconQrCode).
