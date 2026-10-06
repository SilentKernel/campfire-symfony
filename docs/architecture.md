# Architecture

campfire-symfony runs the Rails app's behaviour on Symfony 7.4, PHP 8.4 and FrankenPHP. The Rails
code in `reference/` is the specification. Classes and templates are named after the Rails files
they port and cite them where the behaviour isn't obvious (`// reference/app/models/room.rb`).

## Processes

One container, started by `bin/start` (the Rails image's `bin/boot` + Procfile):

```
                    :HTTP_PORT / :HTTPS_PORT
                              │
┌─────────────────────────────▼──────────────────────────────────────────────────────┐
│ frankenphp run  (docker/Caddyfile)                                                 │
│   Caddy: automatic HTTPS (TLS_DOMAIN), gzip ≥ 512 B, request body ≤ 100 MB         │
│   ├─ /assets/*, public/*  → file_server                                            │
│   ├─ /cable               → reverse_proxy 127.0.0.1:TARGET_PORT ──────────────┐    │
│   └─ everything else      → PHP worker threads (PHP_WORKERS, public/index.php)│    │
│        a BinaryFileResponse for a blob → X-Accel-Redirect → Caddy serves      │    │
│        storage/files/xx/yy/key itself                                         │    │
└──────────┬───────────────────────────────┬────────────────────────────────────│────┘
           │ Doctrine (PDO SQLite)          │ SocketBroadcaster                  │
           │                                │ length-prefixed frames             │
           │                                ▼ var/run/cable.sock                 ▼
           │                   ┌─────────────────────────────────────────────────────┐
           │                   │ bin/console campfire:cable                          │
           │                   │   Workerman event loop (ext-event)                  │
           │                   │   actioncable-v1-json WebSockets, channels,         │
           │                   │   stream index, 3 s heartbeat                       │
           │                   └───────────────────────┬─────────────────────────────┘
           │                                           │ DBAL: sessions, memberships
           ▼                                           ▼ (presence), room lookups
   ┌──────────────────────────────────────────────────────────────┐
   │ $CAMPFIRE_STORAGE_PATH (/rails/storage)                      │
   │   db/production.sqlite3   the Rails database (WAL)           │
   │   db/jobs.sqlite3         Messenger queue (async + failed)   │
   │   files/xx/yy/<key>       Active Storage blobs and variants  │
   │   backups/                ONCE pre-backup snapshots          │
   │   caddy/                  certificates (TLS_DOMAIN only)     │
   └───────────────────────────────▲──────────────────────────────┘
                                   │ Doctrine; publishes to cable.sock too
           ┌───────────────────────┴───────────────────────────────┐
           │ bin/console messenger:consume async  × JOB_CONCURRENCY│
           │   push (Web Push), bot webhooks, banned-content       │
           │   removal, blob analysis and purge                    │
           └───────────────────────────────────────────────────────┘
```

- **FrankenPHP** is a single process. Its worker threads share one APCu segment, which holds the
  message fragment cache and the sign-in rate-limit counters, as Rails' cache store is shared by
  its Puma workers. `PHP_WORKERS` defaults to 2 × CPUs. Each worker thread boots the kernel once
  and then serves requests in a loop. Symfony Runtime restarts the worker script after 500
  requests (its default `FRANKENPHP_LOOP_MAX`).
- **campfire:cable** takes the place of the Action Cable server that Rails mounts in Puma, plus
  Redis' pub/sub role. It is one process with one event loop. It reads the database only on
  connect, subscribe and presence actions, with a 20 ms busy timeout, and retries instead of
  blocking the loop. See [realtime.md](realtime.md).
- **messenger:consume** takes the place of resque-pool. Jobs implement `App\Job\AsyncJob`
  (routing in `config/packages/messenger.yaml`). Failures are retried 3 times (1 s, 2 s, 4 s) and
  then moved to the `failed` queue. The queue lives in `jobs.sqlite3`, so the Rails schema gains
  no tables, and the Rails image ignores the file.
- `bin/start` restarts the cable server and the job workers when they exit. If FrankenPHP exits,
  the container stops.
- The CLI processes have their own APCu segments. A fragment one of them renders for a
  broadcast is cached in that process only.

SQLite connections are opened like Rails' adapter does (`App\Database\SqliteMiddleware`): the
same PRAGMAs (`foreign_keys`, `journal_mode=WAL`, `synchronous=NORMAL`, `mmap_size`,
`journal_size_limit`, `cache_size`), a 5 s busy timeout and `BEGIN IMMEDIATE`. Taking the write
lock up front lets concurrent writers queue on the busy timeout instead of failing with
`SQLITE_BUSY`.

The image builds its own SQLite (3.53.4, from the amalgamation) and does not use Debian's
3.46.1. That version has the WAL-reset bug, which corrupted pages when FrankenPHP's worker
threads committed and checkpointed at the same moment (`docs/internal/sqlite-corruption.md`).
Every process in the image loads that one library (`tests/Container/sqlite.sh`).

## A request in worker mode

`public/index.php` returns the kernel to Symfony Runtime. Under FrankenPHP this means
`FrankenPhpWorkerRunner`, which calls `frankenphp_handle_request()` in a loop. The container,
Twig, Doctrine metadata and routes stay in memory. Request state must not:

- Services that hold per-request data implement `Symfony\Contracts\Service\ResetInterface`:
  `Http\Current`, `Http\RailsSession`, `Http\Cookies`, `Http\Flash`, `Database\Transactions`,
  `Cable\SocketBroadcaster`, `Domain\Rooms\RoomNames`, `Domain\Rooms\TrackedRoomVisit`,
  `Twig\View\RequestViewContext` and `Twig\Asset\PreloadLinks`. The kernel calls `reset()` on all
  of them before the next request.
  - `Transactions::reset()` rolls back any transaction a failed request left open. One stray
    write transaction would block every other writer.
  - `SocketBroadcaster::reset()` flushes pending broadcasts.
  - DoctrineBundle resets the entity manager, so no entity survives into the next request.
- Values that depend only on configuration are kept across requests on purpose: derived keys
  (`Rails\KeyGenerator`), asset tags (`Twig\Asset\Assets`) and the cable socket connection.

The listeners mirror the Rack middleware and Rails `before_action` chain, in order:

| Stage | Listener | Rails counterpart |
|---|---|---|
| request, 2048 | `Http\EventListener\AssumeSslListener` | `ActionDispatch::AssumeSSL` (unless `DISABLE_SSL`) |
| request, 33 | `Http\EventListener\RailsRoutingListener` | Journey router: path normalization, escaped-segment matching, a wrong verb is 404 rather than 405 |
| request, 30 | `Http\Platform\SetPlatformListener` | `SetPlatform` concern |
| request, 8 | Security firewall (`main`, lazy) | runs nothing until asked |
| controller, −8 | `Http\EventListener\FilterChainListener` | `ApplicationController`'s before_actions, see below |
| exception, 64 | `ShowExceptionsListener::onHalt` | a halted filter chain (`App\Http\Halt`) becomes its response, unlogged |
| exception, −64 | `ShowExceptionsListener` | `ActionDispatch::ShowExceptions`: `public/4xx/5xx.html` |
| response, 8 | `RackResponseListener::conditionalGet` | `Rack::ETag` + `Rack::ConditionalGet` (weak SHA-256 ETag, `max-age=0, private, must-revalidate`) |
| response, −2048 | `RackResponseListener::commit` | default headers, `X-Version`/`X-Rev`, flash/session/cookie commit, `ActionDispatch::SSL` (HSTS, `secure` cookies), `X-Request-Id`, `X-Runtime` |
| response, −2056 | `Http\EventListener\ThrusterCacheListener` | Thruster drops `Set-Cookie` from publicly cacheable responses |
| response, −4096 / terminate | `Cable\BroadcastFlushListener` | broadcasts go out with the response, as from Rails |

`FilterChainListener` runs the chain in the order Rails runs it. The order was checked with a
callback dump of the reference:

1. `set_version_headers`
2. `Current.request = request`
3. `reject_banned_ip` for non-GET/HEAD requests (429)
4. `require_authentication`, unless the action has `#[AllowUnauthenticatedAccess]` or
   `#[RequireUnauthenticatedAccess]`
5. `deny_bots`, unless `#[AllowBotAccess]` (403)
6. `verify_authenticity_token`, unless the request is bot-authenticated or the action has
   `#[SkipForgeryProtection]` (422)
7. `allow_browser`
8. for `#[RequireUnauthenticatedAccess]`: `restore_authentication`, then
   `redirect_signed_in_user_to_root`

Controller-specific `before_action`s stay in the controller (`ensureCanAdminister()`, room
scoping, …). A filter that renders or redirects returns a response, or throws `Halt`.

**Authentication** uses Symfony Security. `Security\Authentication` ports the `Authentication`
concern. When the filter chain reaches `require_authentication`, it marks the request with a mode
and initializes the token storage. That runs the lazy firewall's authenticators:

- `SessionCookieAuthenticator`: the signed `session_token` cookie → `Session.find_by(token:)` →
  `resume_session`. Activity is refreshed at most hourly, and the cookie is re-set on every
  authenticated request.
- `BotKeyAuthenticator`: `params[:bot_key]` = `<id>-<token>` → the bot user.

PHP sessions are off (`framework.session: false`). The only state is Rails' cookies, which
`Http\Cookies`, `Http\RailsSession` and `Http\Flash` read and write.

## Rails → Symfony

| Rails | Here |
|---|---|
| Active Record models | Doctrine ORM entities in `src/Entity` mapped onto the Rails tables. `Room` uses single-table inheritance on `type` (`Rooms::Open/Closed/Direct`). Changing a room's type (`becomes!`) is a DBAL `UPDATE rooms SET type` plus a refresh, because Doctrine never changes a discriminator. |
| `update_all`, `insert_all`, `delete_all`, FTS5, hot aggregates, the cable server's queries | DBAL (`Doctrine\DBAL\Connection`) |
| Model callbacks, multi-record behaviour | `src/Domain/*` services (`MessageCreator`, `Rooms`, `Memberships`, `Bans`, …) |
| `after_*_commit` | `Database\Transactions::afterCommit()`: runs after the outermost commit, dropped on rollback. Broadcasts, search indexing and job dispatch go there. |
| Timestamps | `Database\TimestampListener` (onFlush, Rails' `should_record_timestamps?` rules); `rails_datetime` DBAL type for the text format |
| `db/schema.rb`, migrations, `db:prepare` | `db/schema.sql` (Rails' `sqlite_master` DDL) + `db/versions.json`, loaded by `campfire:install` (`Database\Schema`). Migrations aren't ported: a database with a pending migration is refused. |
| `before_action`, `skip_before_action`, class macros | `FilterChainListener` + attributes in `App\Http\Attribute` |
| `has_secure_password` | `Rails\Password` (bcrypt `$2a$`, cost 12, via `crypt()`) |
| `Authentication` concern, sessions | Symfony Security authenticators (above) + `Session` entity |
| `rate_limit to: 10, within: 3.minutes` (sign-in) | `Domain\Users\SignInRateLimiter`: Symfony RateLimiter, fixed window, stored in the APCu `cache.app` pool |
| `params`, strong parameters | `Http\Params` (Rack's nested-bracket parsing, JSON bodies), `Http\Concerns\PermitsParams` |
| `respond_to`, `head`, `redirect_to`, `render turbo_stream:` | `ApplicationController` helpers (`respondTo()`, `head()`, `redirectTo()`, `turboStream()`), `Http\Mime` |
| Routes | `#[Route]` attributes named as in `bin/rails routes` (`room_messages`, `user_sidebar`). Each route's priority is its position in the Rails route table, since Rails matches in definition order. |
| ERB views | Twig templates at the same relative paths (`templates/messages/_message.html.twig`). `Twig\Escaper\ErbEscaper` escapes exactly like `ERB::Util.html_escape` (`'` → `&#39;`). |
| Helpers | Twig extensions in `src/Twig`, with functions named after the Rails helpers (`avatar_tag`, `local_datetime_tag`, `button_to`, `turbo_stream_from`, `dom_id`) |
| Fragment caching (`cached: true`) | `View\MessageRenderer`: one multi-get from APCu per page, then batch loading of the misses (`MessagePresentationLoader`, as reference commit 659f957 does). Keys are the message cache key + `presentation-v3` + a template digest. |
| Propshaft + importmap-rails | AssetMapper with Propshaft's load path at the root namespace (`config/packages/asset_mapper.yaml`) and Rails' importmap keys (`importmap.php`). `PropshaftCssAssetUrlCompiler` resolves CSS `url()` like Propshaft. |
| Action Text (sanitizer, attachments, plain text, autolink) | `App\RichText`. It ports rails-html-sanitizer's `PermitScrubber` and Loofah's HTML5 scrubbing onto PHP 8.4's `Dom\HTMLDocument`, and serializes like Nokogiri. |
| Action Cable + Redis | `campfire:cable` (Workerman) + `Cable\SocketBroadcaster` over a unix socket |
| Resque / Active Job | Symfony Messenger, Doctrine transport in `jobs.sqlite3` |
| Active Storage + image_processing + ruby-vips | `App\Storage`: blobs, disk service, signed ids and URLs, tracked variants (`Processing\Vips`, php-vips through FFI), video previews and analysis (ffmpeg/ffprobe), Marcel MIME tables |
| `restricted_http` + surfguard (link unfurling) | `Opengraph\PrivateNetworkGuard` (surfguard's default policy, address pinning) + Symfony's `NoPrivateNetworkHttpClient` |
| web-push gem, `WebPush::Pool` | minishlink/web-push in `Push\WebPushPool` |
| rqrcode | bacon/bacon-qr-code, with rqrcode's mask selection (byte-identical SVG) |
| `ActiveSupport::MessageVerifier`/`MessageEncryptor`, cookie jars, GlobalID | `App\Rails` (framework-free, vector-tested). See [rails-compatibility.md](rails-compatibility.md). |
| Thruster | Caddy inside FrankenPHP, plus `ThrusterCacheListener` |

### Why not Symfony HtmlSanitizer

Message bodies are rendered through the same layers as Action Text: sanitize, render
attachments, then `auto_link` with regular expressions over the serialized HTML. Byte-identical
output therefore needs Loofah's exact rules:

- disallowed HTML elements are unwrapped, not dropped;
- foreign (SVG/MathML) elements are dropped together with their contents;
- Loofah's URI protocol checks and attribute re-escaping apply;
- the markup is serialized exactly as Nokogiri does.

HtmlSanitizer has a different model and serializer. `RichText\Sanitizer\SafeListSanitizer` and
`RichText\Html\Html` port these rules onto `Dom\HTMLDocument`. Its parser, lexbor, implements the
same WHATWG tree construction as Gumbo, which Nokogiri uses. Fragments are parsed in a `body`
context as Nokogiri does, with Nokogiri's tree-depth and attribute limits (400).

The result is checked against Rails on 658 corpus cases (`tests/fixtures/richtext`) and on every
seed message.

## Directory layout

| Path | What lives there |
|---|---|
| `src/Rails/` | Rails contracts with no framework dependency: key generator, message verifier and encryptor, metadata envelope, cookie codecs, CSRF tokens, forgery protection, signed ids, GlobalID/SGID, Turbo stream names, ActiveSupport JSON, Ruby float formatting, Marshal, bcrypt |
| `src/Http/` | Request pipeline: `Current`, `RailsSession`, `Cookies`, `Flash`, `Csrf`, `Params`, `Mime`, filter/response listeners, error pages, browser policy and user-agent parsing (`Platform/`) |
| `src/Security/` | Authenticators, user provider, `Authentication` (the concern), secure tokens |
| `src/Controller/` | One class per Rails controller, same names and namespaces (`Rooms\OpensController`, `Accounts\Bots\KeysController`, `ActiveStorage\…`, `Rails\HealthController`) |
| `templates/` | Twig ports of `app/views` (and the turbo-rails/Action Text layouts), same relative paths |
| `src/Twig/` | Helper extensions, ERB escaper, `dom_id`/tag/turbo-stream builders, asset tags |
| `src/View/` | `MessageRenderer` (fragment cache) and the presentation loader |
| `src/Entity/`, `src/Repository/` | Doctrine mapping of every Rails table, repositories |
| `src/Domain/` | Model behaviour across records: `Messages` (create/update/destroy, broadcasts, search, pagination), `Rooms` (memberships, sidebar, names), `Users` (bans, bots, first run, push, transfers, rate limiter), `Accounts` |
| `src/Database/` | SQLite middleware, `Transactions`, `Schema`, timestamp listener, `rails_datetime` type |
| `src/RichText/` | Action Text: sanitizer, canonicalizer, attachables (mentions, opengraph, remote media), plain text, autolink |
| `src/Storage/` | Active Storage: blob service, disk service, URLs, variations, representations, vips, video previewer, analyzer, Marcel, HTTP controllers' helpers, jobs |
| `src/Cable/` | `Broadcaster` interface, `SocketBroadcaster`, control frames, stream names, channels, and the server (`Server/`) |
| `src/Job/` | Messenger messages and handlers: push, bot webhooks, banned-content removal |
| `src/Push/`, `src/Opengraph/` | Web Push delivery; link unfurling with the SSRF guard |
| `src/Command/` | `campfire:install`, `campfire:cable` |
| `src/Asset/` | The Propshaft-compatible CSS URL compiler |
| `assets/` | The original frontend, unchanged: `reference/app/{javascript,assets}`, `vendor/javascript` and the gems' assets (`assets/gems/`) |
| `config/` | Symfony configuration. `services.yaml` maps the environment to parameters. `packages/*.yaml` hold doctrine (two connections), messenger, cache (APCu), security (lazy firewall), asset mapper. |
| `db/` | `schema.sql`, `versions.json` |
| `docker/` | `Caddyfile`, `php.ini` |
| `bin/` | `start` (entrypoint), `check`, `fetch-seed`, `console`, `phpunit` |
| `hooks/` | ONCE `pre-backup`/`post-restore` (SQLite online backup of both databases) |
| `public/` | Static error pages (`404/422/500/502.html`), `robots.txt`, `index.php` |
| `tests/` | `Unit/`, `Functional/`, `Integration/`, `Container/smoke.sh`, golden `vectors/`, Rails-made `fixtures/` |
| `tools/` | `parity/`, `crossruntime/`, `e2e/`: verification against the Rails image |
| `bench/` | The four-app benchmark |
| `reference/` | The Rails app (git submodule, read-only) |
