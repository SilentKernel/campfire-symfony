# Parity report: campfire-symfony vs Rails (once-campfire-rust harness)

Date: 2026-10-05. Verifier: independent run of once-campfire-rust's parity harness (`parity/`,
revision 64f8635, Rails reference 254dd1d) with `campfire-symfony:app` as the candidate. No
application code was changed. The tooling is under `tools/parity/`, and the compact results are
in `tools/parity/results/2026-10-05-full1/`.

## TL;DR

The **whole inventory** ran, on the lean matrix the Rust port gates on: all 5 seeds, 222 states,
956 cells. Every page cell went through Chromium desktop and phone in light and dark. The smoke
states also went through Firefox and WebKit, and the breakpoint sweep was included. The run took
about 55 minutes. The harness reports 16 cells passing and 940 failing. That number mostly measures
two things: the HTTP front proxy (Thruster vs Caddy), and one template bug that shows up on every
room page. With those set aside, as explained below:

| Layer | Cells compared | Identical | Different | Different *only* because of the bugs/diffs listed below |
|---|---|---|---|---|
| Server HTML (`server.norm.html`) | 956 | 458 | 498 | 498 (bugs 1+2) |
| Live DOM (`live.norm.html`) | 940 | 430 | 510 | 510 (bugs 1+2) |
| Accessibility tree (`aria.yml`) | 940 | 938 | 2 | 2 (bug 1, `interactions/notifications_help` phone) |
| Screenshots (pixels) | 940 | 938 | 2 | 2 (bug 1, same two cells) |
| Network (every response's status, header shape, body hash) | 940 | 0 | 940 | 864 explained by proxy headers + bugs 1–4; the other 76 are bugs 4–6 and static-file headers |
| Action Cable frames | 940 | **940** | 0 | – |
| Fragments (turbo-stream/JSON/SVG bodies) | 16 | 16 | 0 | – |

Not a single text-layer difference falls outside the six findings listed below. The DOM, the
accessibility tree, the pixels and the Cable frames are otherwise identical to Rails across the
whole inventory, mutating and multi-user states included.

## Fixes (2026-10-05, after this run)

All six real bugs are fixed. The suite passes on the host and in the dev image (`bin/check`: 3846
tests; PHPStan and php-cs-fixer clean). `campfire-symfony:app` was rebuilt and the affected screens
were re-run (results: `tools/parity/results/2026-10-05-fix1/`).

| # | Fix | Tests |
|---|---|---|
| 1 | `templates/pwa/_browser_settings.html.twig`: `{% set switch %}{{ image_tag(…) }}{% endset %}`, so the captured output stays safe markup. The other `{% set x = helper() %}` assignments in `templates/` hold plain strings (sgid, dom_id, paths), and none of them is printed as HTML. | `tests/Functional/Rooms/NotificationsHelpTest.php` |
| 2 | U+F8FF (`EF A3 BF`) restored in `<em aria-label="the Apple menu">` (`_browser_settings` ×1 in the macro, `_system_settings` ×2). A scan of every non-ASCII character in `reference/app/views` against the Twig ports finds nothing else missing. | same |
| 3 | `messages/boosts/{new,index}` use `turbo_frame_request() ? frame : application`. So do the other templates whose Rails controller declares no layout (accounts, bots, custom styles, users, profiles, push subscriptions, searches, sessions, transfers, welcome, first run). Only `MessagesController` keeps the full layout: with `layout false, only: :index`, its other actions resolve to `layouts/application` (actionview `_write_layout_method`, checked in the reference image). `layouts/turbo_rails/frame` now keeps the newline after `yield :head`, so the boost frames are byte-identical to Rails. | `BoostsControllerTest::testFrameRequestsGetTheMinimalLayout` |
| 4 | `App\Http\EventListener\ThrusterCacheListener` (kernel.response, after the cookie commit) drops `Set-Cookie` when Thruster v0.1.23 would cache the response. The request must be GET/HEAD with no Range and no Upgrade. The response must be 200–399 but not 304, with no `Vary: *`, `Cache-Control` matching `\bpublic\b` and not `no-cache`, and `s-max-age` or `max-age` > 0 (`internal/cacheable_response.go`, `cache_handler.go`). It lives in the app, not in the Caddyfile, so it covers every way the app is served and can be tested. | `tests/Unit/Http/ThrusterCacheListenerTest.php`, avatar/logo/disk assertions in `AvatarsTest`, `LogosTest`, `BlobsTest` |
| 5 | Rack::ETag does skip empty bodies (`no-cache`, no ETag), and the app already did the same. The real difference was the body: the ERB views render `"\n"`, not `""`. `RefreshesController` now builds the stream byte for byte as Erubi does: append, then the blank line, then `"  "` + each replace + `"\n"`. Updated-only refreshes used to differ too. `autocompletable/users/index` now emits the ERB's final newline. Both now get `max-age=0, private, must-revalidate` plus a weak ETag, as in Rails. | `RefreshesControllerTest`, `AutocompletableUsersTest::testNoMatchesIsANewline` |
| 6 | `;charset=UTF-8` came from PHP's SAPI, not Symfony (the app already restored the charset-less type). `default_charset = ""` is now set in `docker/php.ini`. htmlspecialchars and mbstring still default to UTF-8. `respondTo` only adds `Vary: Accept` when the handler rendered, not when it redirected (`_set_vary_header` runs in `render`). | `tests/Unit/Http/PhpIniTest.php`, `MessagesControllerTest::testUpdateReindexesTouchesAndBroadcasts` |

### Re-run of the affected screens

Seeds default, restricted and custom_styles, lean matrix, `--no-allowlist`. The states:
`interactions/notifications_help`, `interactions/boost_*`, `interactions/quick_boost`, `realtime/boosted`,
`rooms/show/designers[/custom_styles]` (room refresh, avatars), `account/bots`, `account/edit/{admin,with_logo*}`
(avatars, logo), `interactions/lightbox` (Active Storage), `interactions/mention_autocomplete/*`,
`interactions/edit_saved`, `realtime/message_edited`, `auth/join/invalid_code`, `auth/sign_in/banned_ip`,
`auth/transfer/expired`, `rooms/opens/new/restricted_member`, `errors/404`. That is 120 cells:

| Layer | Compared | Identical | Different |
|---|---|---|---|
| Server HTML | 120 | **120** | 0 (was: bugs 1+2 in every room page) |
| Live DOM | 120 | **120** | 0 |
| Accessibility tree | 120 | **120** | 0 (was 2: `notifications_help` phone) |
| Screenshots | 120 | **120** | 0 (was 2) |
| Cable frames | 120 | 120 | 0 |
| Network | 120 | 0 | 120, all explained by front-proxy headers; **0 unexplained entries** |

The network layer still "fails" every cell for the same reason as before: Thruster vs Caddy headers
(gzip of tiny and empty bodies, `vary: accept-encoding`, `x-cache`). Checked entry by entry:

- No `set-cookie` on any avatar, logo or disk response. `analyze` now tells the cookie signature
  apart from a header-*name*-only difference on public responses. That remaining one,
  `public-response-header-names`, is Rails' default security headers on send_file responses,
  already listed in `known-differences.md` (Storage).
- `GET /rooms/:id/refresh` and `autocompletable/users?filter=zzz` give `max-age=0, must-revalidate, private`,
  an ETag and body `sha256:01ba4719…` (`"\n"`), the same as Rails.
- `GET /messages/:id/boosts/new` (Turbo-Frame) has the same body hash as Rails (`347dd4e5…`).
- 403/404/429 `head` responses give `content-type: text/html`, and the 400 gives `text/vnd.turbo-stream.html`, with no charset.
- The message update 302 has no `vary: accept`.

Not re-run: the static `errors/*/static` pages, where the remaining difference is Caddy's
`charset=utf-8` and ETag on public files. It is now listed in `known-differences.md` ("Front proxy").
The rest of the inventory was not re-run either.

## Real parity bugs (most severe first)

### 1. Notification-help steps print a literal `<img …>` tag (double-escaped `image_tag`)

- **Where**: `templates/pwa/_browser_settings.html.twig:3` uses
  `{% set switch = image_tag('external/switch.svg', {alt: 'the switch', size: 22}) %}`, then outputs
  `{{ switch }}` at lines 10, 36, 75 and 85, through the `apple` macro and directly. When the
  function's result is assigned with `set`, it loses its `is_safe` marking, so autoescape escapes
  it when it is printed.
- **Visible**: yes. On phone/Android, and wherever the browser-settings help renders the switch
  (Firefox/WebKit desktop), the help dialog shows the text
  `<img alt="the switch" src="/assets/external/switch-….svg" width="22" height="22" />` instead of
  the icon. Screenshot diff in `interactions/notifications_help` @ chromium-phone-light and
  chromium-phone-dark: 1.16M and 1.26M of 2.96M pixels differ. The accessibility tree differs in the
  same cells. The markup is part of every room page (the hidden notifications dialog), so it shows
  up in the server/live layers of 112 states.
- **Diff** (`rooms/show/designers` @ chromium-phone-light, server HTML):
  ```diff
  -  <img alt="the switch" height="22" src="/assets/external/switch-«digest».svg" width="22">
  +  <img alt="the switch" src="/assets/external/switch-«digest».svg" width="22" height="22" />
  ```
  The raw candidate body contains
  `<em>&lt;img alt=&quot;the switch&quot; src=&quot;/assets/external/switch-1MzJUdd.svg&quot; … /&gt;</em>`.
  The normalizer decodes it back to text, so it looks like an attribute-order diff above.
- **Fix**: `{% set switch %}{{ image_tag(...) }}{% endset %}` (captured output stays Markup), or
  `{{ switch|raw }}`. Line 57 and `_system_settings.html.twig:58` call `image_tag` inline and are
  fine.

### 2. Apple logo glyph (U+F8FF) dropped from "Click  in the top left"

- **Where**: `templates/pwa/_browser_settings.html.twig:6` and
  `templates/pwa/_system_settings.html.twig:30,39` render `<em aria-label="the Apple menu"></em>`.
  The ERB (`reference/app/views/pwa/_browser_settings.html.erb:28,53,75`,
  `_system_settings.html.erb:29,38`) has `<em aria-label="the Apple menu"></em>`, with the
  private-use Apple logo character (bytes `EF A3 BF`) inside. The character was lost when the
  templates were ported.
- **Visible**: on Apple platforms (the glyph only renders with Apple fonts). Elsewhere, an empty
  `<em>`. It is in every room page (server/live layers of 112 states). No pixel difference on the
  Linux browsers, which have no glyph for it.
- **Diff** (`rooms/show/designers` @ chromium-desktop-light):
  ```diff
   <em aria-label="the Apple menu">
  -  
   </em>
  ```

### 3. Turbo-Frame requests for boosts get the full application layout

- **Where**: `templates/messages/boosts/new.html.twig:2` and `templates/messages/boosts/index.html.twig:2`
  always `{% extends 'layouts/application.html.twig' %}`. turbo-rails renders
  `layouts/turbo_rails/frame` for any request with a `Turbo-Frame` header, unless the controller
  declares its own layout (only `MessagesController` does, which is why `messages/edit` is
  correctly full-layout). The pattern
  `{% extends turbo_frame_request() ? 'layouts/turbo_rails/frame.html.twig' : … %}` already exists
  in the sidebar, involvement and rooms templates. It is missing in the two boosts templates.
- **Effect**: the boost picker and boost list frames ship about 240 extra lines (every stylesheet
  link, importmap, meta tags, a second CSRF meta) per click. The DOM after Turbo extracts the frame
  is the same, which is why only the network layer catches it. It affects
  `interactions/boost_picker{,/filled,/cancelled}`, `interactions/boost_created`,
  `interactions/quick_boost` and `realtime/boosted` (`GET /messages/:id/boosts[/new] → 200`, body
  hash differs, extra `Link` header).
- **Evidence**: `bench/bin/runner tools/parity/bin/probe -H 'Turbo-Frame: x' /messages/933434506/boosts/new`
  gives Rails `<html><head><meta name="csrf-param"…><meta name="csrf-token"…></head><body>…` and
  Symfony `<!DOCTYPE html>… <title>Campfire</title> <meta name="viewport"…> … 20+ stylesheet links …`.

### 4. `Set-Cookie` (including `session_token`) on publicly cacheable responses

- **Where**: the session/cookie commit (`src/Http/RailsSession.php::commit`, the session_token
  refresh) combined with `docker/Caddyfile`. Rails/Puma also emits `Set-Cookie` here (checked
  directly on Puma's port 3000 in the reference container). Thruster, which always fronts Rails in
  the shipped image, removes it from cacheable responses. Caddy, which plays Thruster's part here,
  doesn't.
- **Effect**: `GET /users/:signed_id/avatar` (`cache-control: public, max-age=1800`) carries
  `Set-Cookie: _campfire_session` **and `session_token`**. `GET /account/logo` and the Active
  Storage disk responses (`public, max-age=3600`) carry `_campfire_session`. A shared cache or CDN
  that ignores `Set-Cookie` could store and replay another user's session cookie. The Rails image
  never sends these. 183 states, in the network layer only.
- **Diff** (every room page; `account/bots` @ chromium-desktop-light):
  ```diff
   GET /users/«signed_id:user/avatar:394959859»/avatar?v=20260102200000 → 200
     cache-control: max-age=1800, public, stale-while-revalidate=604800
  +  set-cookie: _campfire_session; httponly; path; samesite
  +  set-cookie: session_token; httponly; path; samesite
  ```
- **Fix**: drop `Set-Cookie` from responses whose `Cache-Control` is `public`, as Thruster does
  (a Caddy `header` matcher, or a response listener). Not listed in README "Known differences".

### 5. Empty Turbo Stream / prompt-list bodies: `no-cache` and no ETag instead of Rails' private ETag'd 200

- **Where**: `src/Controller/Rooms/RefreshesController.php` (`streams()` returns `''`) and the
  `autocompletable/users` HTML (`filter=zzz`). The ERB templates produce whitespace-only bodies,
  so Rack::ETag still tags them (`max-age=0, must-revalidate, private` + ETag). The Symfony
  responses are exactly empty, so they get `cache-control: no-cache` and no ETag.
- **Effect**: none visible. Conditional GET/304 behaviour differs for the room refresh that runs on
  every cable (re)connection. It affects 121 states (`GET /rooms/:id/refresh?since=…&reason=connection`)
  and `interactions/mention_autocomplete/empty`.
- **Diff**:
  ```diff
   GET /rooms/654632876/refresh?since=1772466780000&reason=connection → 200
  -  cache-control: max-age=0, must-revalidate, private
  +  cache-control: no-cache
  -  body: sha256:01ba4719c80b6fe9
  +  body: empty sha256:01ba4719c80b6fe9
  ```

### 6. Header details on `head`/error and redirect responses (cosmetic)

- `head :forbidden` / `:not_found` / `:too_many_requests` / `:bad_request`
  (`rooms/opens/new/restricted_member` 403, `auth/join/invalid_code` 404,
  `auth/sign_in/banned_ip` 429, `auth/transfer/expired` 400 turbo-stream): Rails sends
  `content-type: text/html` or `text/vnd.turbo-stream.html` with no charset. Symfony sends
  `text/html;charset=UTF-8` and `text/vnd.turbo-stream.html;charset=UTF-8` (Symfony's `Response`
  default charset, likely in `ApplicationController::head()`).
- `PATCH` message → 302 (`interactions/edit_saved`, `realtime/message_edited`): Symfony adds
  `Vary: Accept` to the redirect, and Rails doesn't (likely `respondTo` setting Vary on a
  non-negotiated redirect in `src/Controller/MessagesController.php`).
- Static error pages `/404.html` … `/502.html` (`errors/*/static`): `content-type: text/html; charset=utf-8`
  plus `etag` from Caddy, where Rails/Thruster sends `text/html`. The bodies are byte-identical.

## Harness and masking issues (b)

1. **Asset digests.** AssetMapper names assets `name-XXXXXXX.ext` (7 base64url characters),
   Propshaft `name-<8 hex>.ext`. The harness masks only the hex form, so unpatched every
   stylesheet/script/icon URL differs in every layer. Also, the network layer re-fetches the bodies
   of the unrecognised "non-digested" assets, which in the first smoke run crashed the capture
   process (`apiRequestContext.get: socket hang up` from `network.ts bodyOf`). Fix, in a *copy* of
   the harness (`tools/parity/bin/setup`, three one-line regex patches, the Rust checkout
   untouched):
   - `capture/normalize.ts` `ASSET_DIGEST` also matches `-[A-Za-z0-9_-]{7}` → `«digest»`;
   - `capture/network.ts` `DIGESTED_ASSET` treats it as "named by its digest";
   - `capture/determinism.js` strips it from the per-call-site `Math.random` key. Without this,
     Lexxy's random ids (`lexxy-link-url-…`) differ only because the script URL differs.

   Network-layer request paths still keep the raw digest (unpatched), so asset requests always
   differ there, by design. `tools/parity/bin/analyze` compares them by name. The difference is
   documented in README "Known differences" (c).
2. **Front-proxy headers in the network layer.** Every Rails response passes through Thruster
   (`x-cache`, gzip of every response including images, `vary: accept-encoding` duplicated on
   POSTs). Every Symfony response passes through Caddy (`accept-ranges`, ETags on static files, no
   `x-cache`, gzip only above 512 bytes). That alone fails the network layer of all 940 page cells.
   `analyze` drops `x-cache`, `content-encoding`, `accept-ranges`, `etag`, `last-modified` and the
   `accept-encoding` token of `vary` before classifying. These are infrastructure differences, (c)
   in spirit (README documents `X-Cache` and the asset `Cache-Control`). Bug 4 is the one proxy
   difference with security relevance.
3. **The Rust allowlist** (`pwa/manifest`, a Rust-port deviation) was not used (`--no-allowlist`).
   Symfony reproduces Rails' `&amp;amp;` in the manifest, so the state passes without it.
4. **`page-body-hash`**: in 116 states a navigation's body hash differs in the network layer. Every
   such state also has server/live differences, and those are fully explained by bugs 1+2 (same
   body). The harness doesn't keep non-main bodies, so this attribution is per state, not per
   byte.

## Documented deliberate differences (c) seen in this run

- Asset digest format and `Cache-Control: public, immutable, max-age=31536000` on `/assets/*`
  (README "Known differences").
- No Thruster `X-Cache` (README).
- Nothing in `docs/internal/known-differences.md` showed up as a capture difference: the
  rich-text, storage, cable and rooms items aren't exercised by the seed's states, or are
  byte-identical there.

## What was not covered

- **Lean matrix only**: Firefox/WebKit ran only the smoke states (`realtime/**`, `auth/sign_in`,
  `rooms/show/designers`, `interactions/composer/with_text`, `interactions/lightbox`,
  `interactions/mention_autocomplete/results`). The full matrix (every engine × viewport × scheme)
  wasn't run. It is about 5× longer and only changes pixels, which matched everywhere bugs 1/2 don't
  apply.
- Behaviour outside the seeded states: push delivery, webhooks, unfurling, uploads beyond the
  inventory's fixtures, the bot API beyond its four fragments.
- `src/Push/WebPushPool.php` was edited at 12:25 local time, after the image was built (11:21) and
  during the run. Push isn't exercised by the harness, so the results stand for every other file.
  Rebuild `campfire-symfony:app` before re-running.

## How to reproduce

All of this runs inside the Linux runner. `$BENCH_HOME=/opt/campfire-bench` is on the Docker VM.

```sh
# once: Rust harness + Rails images + default seed (existing bench tooling)
bench/bin/runner bench/bin/setup
# the other four seeds (built by the Rails reference, in the Rust checkout's parity/.seed)
bench/bin/runner bash -c 'cd $BENCH_HOME/once-campfire-rust && PARITY_RUNTIME=docker parity/bin/seed build crowd custom_styles first_run restricted'

docker build -t campfire-symfony:app .                 # rebuild if src/templates/config changed
bench/bin/runner tools/parity/bin/setup                # patched harness copy: $BENCH_HOME/once-campfire-rust-symfony
bench/bin/runner tools/parity/bin/candidate build      # campfire-symfony-candidate = app image + libfaketime

# everything (≈55 min on 12 vCPUs, 5 capture workers); per seed: reference :4111 vs candidate :4112,
# mutating states on fresh servers at +1000/+2000/+3000, all torn down afterwards
bench/bin/runner tools/parity/bin/run-all full1
bench/bin/runner tools/parity/bin/analyze /opt/campfire-bench/once-campfire-rust-symfony/parity/out/full1 > analysis.md
bench/bin/runner tools/parity/bin/residual-network /opt/campfire-bench/once-campfire-rust-symfony/parity/out/full1

# a subset, e.g. one state on one seed
bench/bin/runner tools/parity/bin/candidate compare --seed default --no-allowlist \
  --only 'interactions/notifications_help' --out /opt/campfire-bench/once-campfire-rust-symfony/parity/out/one

# raw responses side by side (signed in as david), e.g. bug 3
bench/bin/runner tools/parity/bin/probe -H 'Turbo-Frame: x' /messages/933434506/boosts/new
```

The HTML reports (with screenshots and pixel diffs) are at
`/opt/campfire-bench/once-campfire-rust-symfony/parity/out/full1/<seed>/report.html` on the VM.
The JSON reports, logs, `analysis.md`/`analysis.json` and `residual-network.txt` are copied to
`tools/parity/results/2026-10-05-full1/`.

### Tooling (`tools/parity/`)

| File | What |
|---|---|
| `docker/Dockerfile`, `docker/entrypoint` | Copy of the Rust `parity/docker/candidate` with `BASE_IMAGE=campfire-symfony:app` and `CMD bin/start`. libfaketime 0.9.10 built the same way. `FAKETIME` frozen plus `CAMPFIRE_FROZEN_TIME`, as for the Rust candidate. |
| `bin/candidate` | Modified copy of Rust `parity/bin/candidate`. It drives the harness copy. Image `campfire-symfony-candidate`, containers `campfire-symfony-cand-PORT`, `PHP_WORKERS=4`, `--reset-host` pointing at itself. |
| `bin/setup` | Copies `$BENCH_HOME/once-campfire-rust` (minus `.git`, `target`) and its seeds, then applies the three digest patches (and fails loudly if upstream changed). |
| `bin/run-all` | All seeds, lean matrix, `--no-allowlist`, 5 workers. |
| `bin/analyze` | Per-seed/per-layer counts. Sets aside known-bug signatures (bugs 1, 2) and proxy headers, classifies network differences (bugs 4, 5, page-body-hash) and prints the leftover diffs per state. |
| `bin/residual-network`, `bin/show-entry`, `bin/probe` | Drill-down helpers. |

After the run, `docker ps` shows no `campfire-reference-*`, `campfire-symfony-cand-*` or
`parity-capture-*` containers, and the instance directories are empty.
