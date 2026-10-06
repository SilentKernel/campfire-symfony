# Cross-runtime report: Rails and Symfony on one shared storage

Date: 2026-10-05. Images: `campfire-reference:app` (Rails, pinned `254dd1d`) and
`campfire-symfony:app` (built 2026-10-05 11:21; no source changed after the build).
Tooling: `tools/crossruntime/` (Python 3 standard library only, plus Docker).

## Verdict

**375 checks: 0 FAIL, 4 INFO.** The drop-in claim held up under testing in both directions. The
two apps share the database, the files and the cookies:

- They take turns on the same storage (Rails → Symfony → Rails → Symfony).
- They also run at the same time on that storage.
- Either one can take over after the other is killed with SIGKILL.
- Either one can take over a database the other created from empty storage.

The 4 INFO results are expected and are not caused by the port:

- **2 × no live delivery across apps.** Each app has its own pub/sub (Redis for Rails, a
  unix-socket broadcaster for Symfony), so a message posted on one app never reaches the other
  app's open WebSockets.
- **2 × FTS gap after SIGKILL.** Rails adds the search-index row in a separate step after the
  message is saved (`after_create_commit`), so a crash between the two leaves a message that
  search can't find. Symfony copies that design. `crash_probe.py` shows Rails itself loses rows
  this way (2 unindexed after 6 kills of Rails; 4 after 4 kills of Symfony, which writes about
  6× faster).

## Setup

- **Shared storage:** one Docker named volume `xrt-storage` mounted at `/rails/storage` in both
  containers (not a macOS bind mount). It is seeded from `var/seed/default`:
  `db/production.sqlite3` plus `storage/` → `files/`, owned by uid 1000.
- **Environment:** both containers get the same `SECRET_KEY_BASE`, `VAPID_PUBLIC_KEY` and
  `VAPID_PRIVATE_KEY` (from `.env.test`) and `DISABLE_SSL=true`.
- **Ports:** Rails on 127.0.0.1:3101, Symfony on 127.0.0.1:3102. Because both are on the same
  host, one cookie jar works against either app, as a browser's would after one app replaces the
  other.
- **Rails processes:** the Rails image runs its whole Procfile (web, redis, workers) through
  `bin/boot`, so its Redis runs inside the container. Its cache, pub/sub and Resque queues are
  per-container.
- **Symfony processes:** frankenphp, campfire:cable and messenger. Its own job queue lives in
  `storage/db/jobs.sqlite3`, which Rails ignores.
- **Checks between phases (no app running):**
  - `PRAGMA integrity_check`
  - `PRAGMA foreign_key_check`, compared with the seed's one deliberately broken fixture row
    (`messages|933434637|users|0`)
  - FTS: row count equals the message count, no missing or orphan rowids, FTS5 `integrity-check`
  - `sqlite_master` byte-identical to the seed
  - `schema_migrations` and `ar_internal_metadata` intact, `journal_mode=wal`
  - Every blob has a file on disk
  - Every new upload is analyzed
  - One variant record per image or avatar, which proves both apps compute the same variation
    digest
- **Container logs:** scanned after every phase for 5xx responses, exceptions, `database is
  locked` and `SQLITE_BUSY`. Known noise is counted and excluded: Thruster's `/up` 502 while
  Puma boots, the deliberate bogus-CSRF test, Rails' push error on the seed's fake subscription,
  and Rails' `DeserializationError` for a message the suite deleted before its push job ran.

## Results per requirement

### 1. Sessions: PASS

| Check | Evidence |
|---|---|
| Rails login cookies (`session_token` + `_campfire_session`) authenticate on Symfony | S1 `session.page` 200; `/users/me/profile` shows david's email |
| CSRF-protected POST on Symfony with **Rails' `<meta csrf-token>`** | S1 `session.csrf`: `POST /rooms/HQ/messages` → 200, message 933434644 created; `POST /searches` → 302 |
| Symfony login cookies on Rails, with Symfony's meta token | R2 `session.page` 200; `session.csrf` 200 (message 933434651) |
| Both apps reject a bogus token / no cookie | 422 on both; 302 → `/session/new` on both |
| Sign-out on Symfony kills a Rails-created session on Rails | S1 sign-out of jason → R2 `session.signout.cross` 302 → `/session/new` |
| Sign-out on Rails kills a Symfony-created session on Symfony | R2 sign-out of kevin → S2 302 → `/session/new` |
| Same, live, with both apps running | C `signout.live`: Rails cookie used on Symfony, signed out there, rejected by the running Rails at once |
| A Rails session whose cookies Symfony re-issued still works on Rails; a Symfony session is still valid at the very end | R2 `session.roundtrip`, R3 `session.final` |
| Per-form CSRF tokens (Rails 8.2 defaults) | C `csrf.perform.*`: the involvement `button_to` form rendered by one app, with its per-form `authenticity_token`, is accepted by the other (302), both ways |
| The rest of the cookie contract | C `cookie.return_to.*` (one app's unauthenticated redirect stores `return_to`, the other's sign-in honours it); `cookie.flash.*` (flash `✓` set by one app, shown and consumed by the other); `cookie.last_room.*` (drives the other app's `/` redirect); `transfer.*` (a session-transfer link from one app's profile signs in on the other). All pass both ways |
| WebSocket authentication across apps | C `ws.*`: each app's `/cable` accepts the session cookie created by the **other** app (welcome, then `RoomMessagesChannel` subscription confirmed) |

### 2. Data written by one app, read by the other: PASS (both directions, every item)

The suite runs in R1 (Rails), S1 (Symfony) and R2 (Rails). It is then checked on the other app in
S1, R2 and S2 respectively.

| Item | Write | Verified on the other app |
|---|---|---|
| Text message with an @mention (SGID from the writer's `/autocompletable/users.json`) | 200 | Rendered as Jason's mention chip (`<span class="mention"><a title="Jason" … href="/users/149087659">`); search finds it |
| Image upload (1600×1000 PNG) | 200 | Thumbnail `<img>` from `/rails/active_storage/representations/redirect/…` is served by the reader: 1200×750 variant, 200 image/png. The original blob is served too |
| Edit | 302 | New body shown; search finds the new word and **not** the old one |
| Delete | 200 | Absent from the room page; not found by search |
| Boost | 302 | Boost content shown on the message |
| Create rooms (open, closed with user_ids, direct) | 302 ×3 | Room page and `/rooms/{opens,closeds,directs}/:id/edit` return 200; sidebar lists them; the open room shows its message |
| Change involvement (`everything`, `nothing`, `invisible`) | 302 | `/rooms/:id/involvement` shows `class="btn <involvement>"` |
| Join via join code (new user + password) | 302 + session | The new user signs in **with that password** on the other app |
| Bot API post (`POST /rooms/:id/:bot_key/messages`, text/plain) | 201 | Shown in the room; returned by the bot API index; found by search |
| Profile change (name, bio) + avatar upload | 302 | `/users/:id` shows the new name and bio; the reader builds the same `/users/<token>/avatar?v=…` and serves the webp |
| Unread state | — | Jason's sidebar (with Jason's cookie from the writer) has HQ marked `unread` after david's posts |
| Other pages | — | `/`, `/users/me/profile`, `/searches`, `/account/edit`, `/account/bots` and `/messages/:id/boosts` all return 200 with no error markers |

**Extra parity checks in the concurrent phase:**

- **Rendered HTML:** for 12 messages (mention, image, edited and bot messages from each suite),
  `messages#show` returns byte-identical presentation HTML from both apps (`parity.*`).
- **Image variants:** the same PNG uploaded through each app gives identical blob metadata and a
  byte-identical `:thumb` variant (checksum `Ue1Y/k0hsnMZwl8vDQjVIA==`, 1972000 B), and the same
  variation digest (`variant.parity`).
- **Avatars:** an avatar uploaded on one app gets the same fresh URL on the other, and both apps
  serve identical bytes.
- **Fragment caches:** after an edit or a boost on one app, the other app (with warm Redis/APCu
  fragment caches) shows the change immediately, on the room page and in `messages#index`.
- **Database row shapes:** I inspected the rows each app writes (with the volume kept via
  `--keep`). Timestamps are 26-character `YYYY-MM-DD HH:MM:SS.ffffff`, bcrypt digests are
  `$2a$12$`, direct room `name` is NULL, and membership involvements match per room type. Rows
  written by Rails and by Symfony look the same.

### 3. Rollback Rails → Symfony → Rails: PASS

- **R2 (Rails after Symfony):** Rails boots normally (`db:prepare`).
  - `bin/rails db:migrate:status`: rc 0, 15 × `up`, no `down` or `NO FILE`. Saved to
    `out/logs/R2-migrate-status.txt`.
  - `ActiveRecord::Migration.check_all_pending!` passes.
  - The boot log has no pending-migration error.
- **R3:** `db:migrate:status` is clean again on the final database, after the concurrent and crash
  phases.
- **Every DB checkpoint (seed, R1, S1, R2, S2, C, K):**
  - `integrity_check` = ok
  - FTS rows = messages (186 after R2, 377 after C)
  - 0 missing and 0 orphan rowids
  - FTS5 `integrity-check` ok
  - `sqlite_master` identical to the seed (Symfony adds no tables)
  - 15 migrations, `environment=production`, still WAL
  - Every blob file present
- **Fresh install takeover (F-symfony, F-rails):** I started each app on empty storage, ran first
  run and posted, then started the other app.
  - The schema Symfony creates is byte-identical to Rails' `sqlite_master`.
  - `schema_migrations` (15) and `ar_internal_metadata` match (`environment=production`,
    `schema_sha1=f75da8da…`).
  - Rails' `db:migrate:status` is clean on the Symfony-created DB.
  - The first-run session and admin password work on the other app.
  - Posts, search and account settings work.
- **Crash takeover (K-symfony, K-rails):** I killed one app with SIGKILL while 4 threads were
  posting, then started the other.
  - Every acknowledged post is durable.
  - The survivor writes normally.
  - `integrity_check` ok and FTS5 `integrity-check` ok.
  - The only gap is the reference design's after-commit FTS window described in the verdict
    (INFO).

### 4. Simultaneous use and realtime: PASS, with cross-app live delivery absent as expected

- **Concurrent writes:**
  - 90 posts on Rails and 90 on Symfony, 6 threads each, all at the same time: 180/180 → 200 in
    2.0 s. Worst latency was 0.52 s on Rails and 0.25 s on Symfony.
  - No `database is locked` or `SQLITE_BUSY` in either log.
  - Both apps' search finds all 180.
- **Read-after-write:** a post on either app is on the other's room page and in its search results
  immediately.
- **Presence:** this works across apps because it lives in the DB
  (`memberships.connections/connected_at`).
  - While Jason is subscribed to `PresenceChannel` on app A, a post on app B leaves HQ read for
    him.
  - After he unsubscribes on A, the next post on B marks HQ unread.
  - Verified both ways.
- **Live delivery:** a `RoomMessagesChannel` subscriber gets the turbo-stream for posts on its own
  app (`live.*.self` PASS) but **not** for posts on the other app (`live.*.cross` INFO, "not
  delivered"). This is by design: pub/sub is per app. Users on the other runtime see the message
  on refresh or reconnect, not live. Don't run both at once in production except during a short
  switch-over.

## Bugs found (most severe first)

No cross-runtime defects were found in sessions, CSRF, data, files, schema, search or migrations.
Two minor divergences surfaced:

1. **Symfony deletes push subscriptions that Rails keeps (low severity, undocumented).**
   - **What happens:** the seed subscription `782661004` (david, Mozilla endpoint) has a
     `p256dh_key` that isn't valid base64. Rails' `WebPush::Pool#deliver` only invalidates on
     `WebPush::ExpiredSubscription` or `OpenSSL::OpenSSLError`. Here it gets an `ArgumentError
     invalid base64`, logs `Error in WebPush::Pool.deliver` and **keeps** the row.
   - **Symfony:** catches `\Throwable` around `$webPush->request(...)` and deletes the row.
   - **Observed:** `push_subscriptions` went from 5 (seed) to 1 after R1 (Rails destroyed 4 with
     OpenSSL errors) and to 0 after S1 (Symfony deleted the one Rails kept).
   - **Likely file:** `src/Push/WebPushPool.php` lines 93–103. The comment there says "Rails: an
     OpenSSL error … invalidates", but the code catches everything.
   - **Fix:** narrow the catch to the key and crypto errors Rails maps to `OpenSSLError`, or record
     the difference in README "Known differences".
   - **Impact:** such a subscription can never be delivered anyway.
2. **The same-second avatar URL collision is a reference limitation, not a port bug (no action).**
   - `fresh_user_avatar_url`'s `v=` is `updated_at.to_fs(:number)`, which only changes once per
     second, and Thruster caches avatars publicly by URL.
   - Two avatar uploads in the same second, even on Rails alone, give the same URL, and Rails'
     Thruster can serve the stale image.
   - The concurrent avatar check waits 1.2 s between uploads; with that pause both apps agree.

**Other observations (not bugs):**

- `GET /rooms/:id/settings` returns 500 on Rails (a route with no controller) and 404 on Symfony.
  This is deliberate and documented in `src/Controller/Rooms/SettingsController.php`.
- `GET /account/users` returns 406 on both apps, so they behave the same.
- WAL size: under a sustained burst, Symfony's higher write rate grew the `-wal` file to 49–72 MB
  before SIGKILL, against about 5 MB for Rails.
  - `wal_probe.py` (3 rounds of 160 concurrent posts, then idle) shows both runtimes settle and
    reuse the WAL: Rails 5.5–7.3 MB, Symfony 9.9–10.2 MB, no unbounded growth.
  - The PRAGMAs are identical (`src/Database/SqliteMiddleware.php` = Rails `DEFAULT_PRAGMAS`).
- Symfony logs `InvalidAuthenticityToken` and `UnknownFormat` as `Uncaught PHP Exception` at
  ERROR level. Rails also logs the CSRF failure at ERROR.

## Reproduce

```bash
cd campfire-symfony
docker build -t campfire-symfony:app .        # if src/ changed since the image was built
tools/crossruntime/run.sh                     # ~5 min; prints PASS/FAIL/INFO lines; exit 1 on any FAIL
tools/crossruntime/run.sh --keep              # keep the xrt-storage volume to inspect the DB afterwards
#   python3 tools/crossruntime/xrt_docker.py sql "select count(*) from messages"   (inspect)
#   python3 tools/crossruntime/xrt_docker.py cleanup                               (remove)
python3 tools/crossruntime/crash_probe.py rails 6     # FTS gap after SIGKILL exists in Rails too
python3 tools/crossruntime/crash_probe.py symfony 4
python3 tools/crossruntime/wal_probe.py               # WAL high-water marks per runtime
```

Outputs:

- `tools/crossruntime/out/run.log`: every check with its evidence.
- `tools/crossruntime/out/results.json`: machine-readable results.
- `tools/crossruntime/out/logs/<phase>-<app>.log`: full container logs.

Every script removes its containers (`xrt-rails`, `xrt-symfony`) and volumes (`xrt-storage`,
`xrt-storage-fresh`, `xrt-crashprobe`, `xrt-walprobe`) when it finishes, even if it fails.

Files:

- `tools/crossruntime/crossruntime.py`: the phases and checks.
- `xrt_docker.py`: the volume, containers and the in-container `sqlite3`, since the host
  `sqlite3` lacks FTS5.
- `xrt_http.py`: the cookie-jar HTTP client, multipart forms and a PNG generator.
- `xrt_ws.py`: a minimal RFC 6455 / `actioncable-v1-json` client.
- `crash_probe.py`, `wal_probe.py`: the probes above.
- `run.sh`: the wrapper.
