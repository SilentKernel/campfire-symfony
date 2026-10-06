## Rich text (W4)
- Parity with Rails: presentation 656/658, plain text 655/658, editor 653/658, mentions 658/658, canonical 664/669, seed messages 169/169 byte-identical. Remaining diffs are fuzzed malformed markup parsed differently by PHP's HTML5 parser (lexbor) vs Nokogiri/Gumbo.
- `<`/`>` escaped inside attribute values before autolinking (closes rails_autolink stored-XSS), as the Rust port.
- `name` attributes dropped (DOM clobbering), as the Rust port.
- Mentions of deleted users render ☒ instead of blanking the message; omitted in the editor.
- Content attachments nested more than 8 levels render empty.
- Sanitizer is a port of Rails' PermitScrubber/Loofah on PHP 8.4 Dom\HTMLDocument (Symfony HtmlSanitizer could not reproduce Loofah's rules/serialization).
## Storage and media (W6)
- Variants byte-identical to Rails for libvips 8.16.1 (thumb, avatar, logo, video poster). The upstream vector alpha-centuri-preview_image.jpg came from a different ffmpeg build; our ffmpeg 7.1.5 output equals the seed's preview byte for byte.
- send_file/proxy responses carry Rails' default security headers (Rails Live streaming omits them).
- Disk show: Accept-Ranges: bytes; offloaded disk files get Caddy's ETag/304 handling.
- Flash etagger skipped (Rails' value is a non-deterministic object inspect).
- Blob files are written before the row, not after (orphan file instead of orphan row on failure).
- ffmpeg killed after 60 s, ffprobe after 30 s (Rails: no limit).
## Realtime / jobs (W7)
- Cable limits Rails doesn't have: 64 subscriptions/connection, 4 KB identifiers, 1 MB messages, 256 pending commands.
- No permessage-deflate compression.
- Banned/deactivated users refused at connect (Rails only checks the session exists).
- Re-subscribing an identifier doesn't create duplicate streams.
- Broadcasts published while the cable server is down are dropped (Rails: Redis pub/sub drops them likewise when no subscriber).
- Without ext-event (dev hosts) the loop falls back to stream_select (≈1000 sockets max). The image ships ext-event.
## Rooms (W3)
- Room destroy deletes in bulk (same end state as Rails' per-message callbacks); blobs purged later.
- Membership insert_all timestamps come from SQLite (like Rails), not the frozen app clock.
## Identity / admin (W1+W2)
- Incompatible-browser page is always HTML regardless of requested format.
- Test notification push errors are logged / subscription removed instead of raising.
- PWA manifest keeps Rails' `&amp;amp;` in the small logo URL (Rust port fixes it).
- QR codes byte-identical to rqrcode (mask re-selected with rqrcode_core scoring).
## Messages (W5)
- Messages page ETag values differ (own template digest); 304 behaviour identical.
- Unfurl timeouts: 5 s idle / 10 s total per request (Rails 60 s); curl sets its own Accept-Encoding.
- Private-network block list is a PHP reimplementation of surfguard's default policy, double-checked by NoPrivateNetworkHttpClient.
## Resolved (integration)
- Foundation Http tests that expected 501 stubs now assert the real actions (no "Not implemented" stub remains); action-error and empty-error-page cases use an injected 501.
- Route loader (AppRoutes) loads only instantiable classes under src/Controller (no abstract bases, traits).
- App\Http\Halt handled at priority 64 and propagation stopped, before Symfony's ErrorListener logs it (regression test).
- MessageCreator::create(..., deliverWebhooks: false) for bot webhook replies (text and attachment), as Webhook#receive_*_reply_to.
- WebPushGateway delivers through App\Push\WebPushPool (pinning, expiry deletion).
- Prod monolog: no deprecation handler (Rails `report_deprecations = false`).
- src/View/AttachmentUrls removed; StorageUrls is the only URL builder.
- messages/edit renders `value=""` for an empty editable body, as Lexxy (verified in campfire-reference:app).
- fresh_account_logo_path(size) emits size before v; the PWA manifest uses it.
- HasOneAttached removed: avatars and logos use BlobService::createFromUpload + Storage\Attachments::attach.
- RoomsTestCase/MessagesTestCase read the session cookie with getRawValue().
- MessagePresentationLoader names direct rooms through Domain\Rooms\RoomNames (Rails' SQL, memoized).
- Room page parity tests now compare whole pages incl. the message list: group direct room messages render as Rails (no unrenderable).
- messages/_template.html.twig exists; parity tests no longer skip it.
- Tests compile their container in a per-process cache dir (tests/bootstrap.php), so parallel/stale runs can't share var/cache/test.
- Push: undecodable subscription keys are logged and kept (Rails ArgumentError); decodable-but-invalid keys are deleted (Rails OpenSSL error). Fixed after cross-runtime check.
## Front proxy: Thruster vs Caddy (parity harness, network layer)
- The Rails image answers through Thruster, this one through Caddy (FrankenPHP). Header-only differences that remain by design: no `X-Cache`; Caddy gzips only bodies of 512 bytes or more (Thruster gzips everything, images included) and streams them chunked; `Vary: Accept-Encoding` is not added to uncompressed responses; `Accept-Ranges`, `ETag` and `Last-Modified` on files Caddy serves (public/, assets, offloaded Active Storage files).
- Static error pages (`/404.html` … `/502.html`): Caddy sends `text/html; charset=utf-8` plus an ETag, Thruster/Rails `text/html`. Same bytes.
- Thruster's one behaviour with security weight, dropping `Set-Cookie` from publicly cacheable responses, is reproduced in the app (`App\Http\EventListener\ThrusterCacheListener`, Thruster v0.1.23 rules), not in the Caddyfile. Thruster's response cache itself (serving hits with `X-Cache: hit`) is not reproduced.
- `default_charset = ""` in docker/php.ini: otherwise PHP's SAPI appends `;charset=UTF-8` to charset-less `text/*` types (Rails' `head` responses). Host PHP keeps its default, so this only shows in the image.
## SQLite library (bench corruption, 2026-10-05)
- The image ships SQLite 3.53.4 built from the amalgamation in place of Debian trixie's 3.46.1, which has the WAL-reset bug (https://sqlite.org/wal.html#walresetbug). That bug caused the 46 × "database disk image is malformed" 500s in bench rep 4 (concurrent commits and auto-checkpoints across FrankenPHP's worker threads). Rails' sqlite3 gem bundles 3.53.2, so both images now run fixed SQLite. Same compile options as Debian; PRAGMAs unchanged. Root cause, evidence and reproducers: docs/internal/sqlite-corruption.md; checks: tests/Container/sqlite.sh, tools/sqlite-stress/.
