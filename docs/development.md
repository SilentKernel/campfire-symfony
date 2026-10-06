# Development

Read [AGENTS.md](../AGENTS.md) first. It has the compatibility contract, the ownership of each
directory, the core APIs (clock, transactions, `Current`, broadcaster, jobs) and the working
rules. This page covers the tooling.

## Setup

**Requirements:**

- PHP 8.4 with `apcu`, `ffi` (libvips), `gmp` and `bcmath` (Web Push), `intl`, `pcntl`,
  `pdo_sqlite`, `sodium`, `sockets`. `event` is optional for the cable server.
- libvips and ffmpeg.
- Composer.
- Docker, for the seed, the reference image, `bin/check` and the verification tools.

The Dockerfile's `base` stage is the authoritative list of extensions.

```sh
git submodule update --init                       # reference/: the Rails app, pinned at 254dd1d
composer install
printf 'APP_ENV=dev\nAPP_DEBUG=1\n' > .env.local    # .env defaults to prod
bin/console campfire:install                      # storage/db/development.sqlite3 (CAMPFIRE_DATABASE_NAME)
symfony serve                                     # or: php -S 127.0.0.1:8000 -t public
```

- `.env.dev` provides a development `SECRET_KEY_BASE` and `DISABLE_SSL=true`.
- In `dev`, AssetMapper serves assets on the fly, the cache is the filesystem rather than APCu,
  and Symfony's error pages replace `public/500.html`.
- To work on a copy of the seed, set `CAMPFIRE_STORAGE_PATH` to a directory that contains
  `db/production.sqlite3` and `files/`, with `CAMPFIRE_DATABASE_NAME=production`. Never point it
  at `var/seed/` itself.
- The development server doesn't proxy `/cable`, so pages work without realtime.
  `bin/console campfire:cable` and `bin/console messenger:consume async` run on their own.
- For the full stack (Caddy, realtime, jobs), build and run the image:

  ```sh
  docker build -t campfire-symfony:app .
  docker run --rm -p 8080:8080 -e SECRET_KEY_BASE=dev -e DISABLE_SSL=true -e HTTP_PORT=8080 \
    -v campfire-dev:/rails/storage campfire-symfony:app
  ```

`CAMPFIRE_FROZEN_TIME=<ISO 8601>` freezes the application clock, as the parity harness does.
App code must take the time from the injected `ClockInterface`.

## Seeds and labels

Most functional tests need the **parity seed**. It is a database and Active Storage tree created by
the Rails app through once-campfire-rust's seed scripts, from the reference test fixtures. Build it
once on the Docker VM, then copy it into the repository:

```sh
bench/bin/runner bench/bin/setup     # Rust harness, Rails images, seed, load generator in $BENCH_HOME
bin/fetch-seed                       # → var/seed/default (FORCE=1 refreshes)
```

- `var/seed/default/labels.json` maps fixture names to ids and values, for example
  `rooms.watercooler`, `emails.david` and `passwords.all`. Tests read it through
  `CampfireTestCase::labels()`.
- `CampfireTestCase` gives each test its own copy of the seed under `var/test-storage/` and
  points `CAMPFIRE_STORAGE_PATH` at it. The seed itself is never opened.
- The other parity seeds (`crowd`, `custom_styles`, `first_run`, `restricted`) are only used by
  the parity harness.

## Tests

```sh
php bin/phpunit                          # everything (3,846 tests)
php bin/phpunit tests/Unit/Rails         # one directory
php bin/phpunit --filter RoomsTest       # one class
vendor/bin/phpstan                       # level 6 over src/
vendor/bin/php-cs-fixer fix              # @Symfony rules
```

- **Host vs image.** Host PHP is fine for iteration. `bin/check` builds the Dockerfile's `dev`
  stage and runs PHPUnit, PHPStan and a php-cs-fixer dry run inside it. That stage has the
  production PHP 8.4 ZTS build, the same extensions and `php.ini`, and libvips/ffmpeg versions
  that match the Rails image. It mounts `var/seed` and `reference/` read-only. Its result is the
  authoritative one. Media byte-identity tests in particular depend on those library versions.
  - `bin/check phpunit --filter X` runs one tool with arguments.
- **Per-process container cache.** `tests/bootstrap.php` gives each PHPUnit process its own
  `APP_CACHE_DIR` (`var/cache/phpunit-<pid>-<random>`) and deletes it afterwards. Parallel runs
  (several checkouts, agents, or the dev image with the repository mounted) never share a
  half-compiled container, and a run never reuses one compiled from other sources. Set
  `APP_CACHE_DIR` yourself to keep the cache between runs.
- **Test environment.** The `test` environment uses an in-memory Messenger transport, an array
  cache and the `RecordingBroadcaster`.
- **Container smoke test.** `tests/Container/smoke.sh` builds the production image and runs it as
  an arbitrary uid on fresh storage and on a copy of the seed. It checks `/up`, compiled assets
  and their headers, process supervision (a killed worker is restarted, a dead FrankenPHP stops
  the container) and the ONCE backup/restore hooks.
- **Cable integration tests** (`tests/Integration/Cable`) start a real `campfire:cable` process
  and talk to it over WebSockets and the publish socket.

## Golden vectors and Rails fixtures

- `tests/vectors/` comes from once-campfire-rust and was generated by Rails. Never edit it. See
  [rails-compatibility.md](rails-compatibility.md#golden-vectors) for the contents and how to
  regenerate them.
- `tests/fixtures/richtext/run.sh` and `run_seed.sh` regenerate the rich-text expectations with
  the Rails pipeline in `campfire-reference:app`.
- When you need to know what Rails does, run it. Read the gem source inside the reference image,
  not documentation or memory:

  ```sh
  docker run --rm --entrypoint bash campfire-reference:app -c 'cat $(bundle show actionpack)/lib/action_dispatch/middleware/cookies.rb'
  ```

## Verification tools

All three compare `campfire-symfony:app` with the unmodified Rails image (`campfire-reference:app`,
built by `bench/bin/setup`). Rebuild the Symfony image first if `src/`, `templates/` or `config/`
changed.

| Tool | What it does | Run | Report |
|---|---|---|---|
| `tools/parity/` | once-campfire-rust's parity harness with Symfony as the candidate. It compares server HTML, live DOM, accessibility tree, screenshots, network and Cable frames for every screen state of the 5 seeds. `bin/setup` makes a patched copy of the harness that masks AssetMapper's digest format. | `bench/bin/runner tools/parity/bin/setup`, `… candidate build`, `… run-all NAME`, `… analyze DIR`. Subsets: `candidate compare --seed default --no-allowlist --only 'interactions/notifications_help'`. Raw responses side by side: `bin/probe`. | [parity-report.md](internal/parity-report.md) |
| `tools/crossruntime/` | Rails and Symfony on one Docker volume: alternating, concurrent, after SIGKILL, from empty storage. It checks sessions, CSRF, data both ways, schema and migrations, FTS integrity and variants. Python 3 standard library only. | `tools/crossruntime/run.sh [--keep] [--rebuild]` (about 5 min); `crash_probe.py`, `wal_probe.py` | [crossruntime-report.md](internal/crossruntime-report.md) |
| `tools/e2e/` | Helpers for browser scenarios with agent-browser: start a container on a seed copy or fresh storage (`up.sh`), two session wrappers (`ab-a`, `ab-b`), a JS error gate (`errors.sh`), typing/delete/reload probes, a DOM dump for diffing against Rails | `tools/e2e/up.sh symfony-seed`, `tools/e2e/up.sh reference-seed`, … `up.sh down` | [e2e-report.md](internal/e2e-report.md), screenshots in `docs/evidence/e2e/` |

`bench/bin/runner` runs these in a Linux container that drives the host's Docker engine, because
macOS lacks GNU userland, `taskset` and cgroups. `$BENCH_HOME` (default `/opt/campfire-bench`)
lives on the Docker VM's own filesystem, because SQLite's WAL isn't safe on virtiofs.

## Conventions

The full list is in [AGENTS.md](../AGENTS.md). In short:

- **The Ruby in `reference/` is the specification.** Name things after it: one controller class
  per Rails controller, route names from `bin/rails routes`, templates at the ERB's relative path,
  Twig functions named after Rails helpers. Cite the Rails file in a comment where the behaviour
  isn't obvious.
- **The markup must equal the ERB's**: ids (Rails' `dom_id`, with STI class names), classes, data
  attributes and escaping (`'` → `&#39;`). The unchanged Stimulus controllers depend on it.
- PHP 8.4 with `declare(strict_types=1)`, final classes, readonly and constructor promotion,
  attributes for routes, mapping and listeners.
- **Worker mode:** no state may survive a request. A service holding request data implements
  `ResetInterface`. Take the time from `ClockInterface`.
- **Writes** go through `Transactions::transaction()`. Side effects that Rails runs after commit
  (broadcasts, search index, jobs) go in `afterCommit()`. Bulk statements, FTS and hot
  aggregates use DBAL.
- **Never** edit `reference/`, `tests/vectors/` or `.cache/`. Never add tables or columns to the
  Rails database.
- Record every deliberate difference from Rails in README "Known differences".

## Adding or changing a feature

1. **Read the Rails code** for the route, controller, model concerns, views, helpers, channels
   and jobs involved. Check the behaviour in the reference image when Rails or gem internals
   matter.
2. **Route:** add a `#[Route]` with the Rails route name and its position-based priority. Check
   that `tests/Functional/Routing` still passes against the Rails route vectors.
3. **Controller:** put it in the namespace of the Rails controller and extend
   `ApplicationController`. Express `allow_unauthenticated_access`, `require_unauthenticated_access`,
   `allow_bot_access` and `skip_forgery_protection` as attributes. Keep controller
   `before_action`s in the controller. Keep status codes (302 by default, 303/422 where Rails
   says so) and formats (`respondTo()`).
4. **Model behaviour** goes in a `Domain` service. Writes run in a transaction, and callbacks
   become `afterCommit()` closures: broadcasts through `Broadcaster`, jobs as `AsyncJob`
   messages.
5. **View:** port the ERB to Twig at the same path. Add any missing helper to the matching
   extension in `src/Twig`, named like the Rails helper. A frame-only response extends
   `layouts/turbo_rails/frame.html.twig` when `turbo_frame_request()` is true.
6. **Realtime:** use stream names from `StreamNames`. A new channel goes in
   `src/Cable/Channel` and is registered in `ChannelRegistry`.
7. **Tests:** add a functional test on the seed (`CampfireTestCase`) and unit tests for any new
   Rails contract. Compare with Rails output where you can: a vector, a fixture generated in the
   reference image, or a parity-harness state.
8. **Check:** run `bin/check` and rebuild the image. Run the parity states that cover the screen,
   and `tools/crossruntime/run.sh` if the change writes data or cookies.
9. **Document** any deliberate difference in README "Known differences".
