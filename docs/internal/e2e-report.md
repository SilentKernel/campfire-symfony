# End-to-end browser verification (2026-10-05)

Independent check of `campfire-symfony:app` in two real headless Chromium sessions
(agent-browser), with the Rails reference (`campfire-reference:app`) run side by side as the
oracle wherever behaviour looked questionable.

**Verdict: all 11 scenarios pass. No port-specific functional bug was found.** Every oddity seen
in the Symfony app also happens in the Rails reference, with the same markup or HTTP exchange
(see "Upstream behaviour").

## Setup

| | |
|---|---|
| Image | `campfire-symfony:app`, built 2026-10-05 11:21. No file in `src templates assets config public docker bin Dockerfile composer.lock importmap.php` is newer, so it was not rebuilt. |
| Symfony runs | `tools/e2e/up.sh symfony-seed` (copy of `var/seed/default`) and `symfony-fresh` (empty volume) on :8090, `-e SECRET_KEY_BASE=<.env.test> -e DISABLE_SSL=true -e HTTP_PORT=8090 -e TARGET_PORT=8091` |
| Rails run | `tools/e2e/up.sh reference-seed` (separate copy of the seed) on :8092 |
| Users | A = david@37signals.com (admin), B = jason@37signals.com; password `secret123456` |
| Browsers | session `cfe2e-a` / `cfe2e-b` (Symfony), `cfr-a` / `cfr-b` (Rails), 1280x900 |
| Tooling | `tools/e2e/`: `volumes.sh`, `up.sh`, `ab-a` / `ab-b` (session wrappers), `errors.sh` (JS error gate), `typing-probe.sh`, `delete-probe.sh`, `reload-probe.sh`, `wrong-password.sh`, `dom-dump.sh` |

Screenshots are in `docs/evidence/e2e/`. Files prefixed `ref-` come from the Rails reference.

## Results

| # | Scenario | Result | Evidence |
|---|---|---|---|
| 1 | Fresh install: the first-run form (name, email, password, avatar moon.jpg) creates the admin and lands in "All Talk" (`/rooms/1`). The avatar is served (512px) and a posted message persists after reload. | PASS | 01-first-run-form.png, 01-first-run-form-filled.png, 01-first-run-all-talk.png, 01-first-run-message-persisted.png |
| 2 | Two users sign in in two sessions and open HQ. 3/3 `turbo-cable-stream-source` connected in each. | PASS | 02-david-hq.png, 02-jason-hq.png |
| 3 | A's message appears for A at once and for B live. B sees "David" in the typing indicator while A types. HQ gets the `unread` class in B's sidebar live while B is in All Pets. Same on Rails. | PASS | 03-david-posted.png, 03-jason-sees-typing.png, 03-jason-receives-live.png, 03-jason-unread-badge.png; ref-03-* |
| 4 | Typing `@Jas` opens the Lexxy prompt (Jason, served from `/autocompletable/users`). Enter inserts the mention chip. B sees the avatar plus bold-name chip. Rendered HTML is byte-identical to Rails. | PASS (see U2) | 04-mention-autocomplete.png, 04-mention-inserted.png, 04-jason-sees-mention.png, ref-04-jason-sees-mention.png |
| 5 | moon.jpg attached through the composer: pending thumbnail, posted as a 640x640 variant, received live by B. The lightbox `<dialog>` opens with the full image. Same blob signed id and markup as Rails. | PASS | 05-upload-pending.png, 05-david-image-posted.png, 05-jason-image-live.png, 05-lightbox.png; ref-05-* |
| 6 | Edit own message: B sees the new text live. Delete (with turbo confirm): gone for A at once and for B within about 0.9s (`delete-probe.sh`). Quick boost 👍: B sees the boost live, with markup identical to `boosts/_boost.html.erb`. | PASS | 06-message-options.png, 06-edit-form.png, 06-david-edited.png, 06-jason-sees-edit-live.png, 06-david-deleted.png, 06-jason-sees-delete-live.png (taken just before the removal reached B), 06-boost-menu.png, 06-david-boosted.png, 06-jason-sees-boost-live.png |
| 7 | All Talk (rooms.watercooler, 131 messages): 40 on load, then scrolling up loads 80, 120 and 131. No duplicate ids, timestamps in chronological order, oldest is "001. Coffee machine is fixed!". | PASS | 07-watercooler-initial.png, 07-watercooler-older-loaded.png |
| 8 | Search "coffee": 13 results, the same ids in the same order as Rails. Clicking one goes to `/rooms/486777696/@933434530` and centres the message with `search-highlight`. | PASS | 08-search-coffee.png, 08-search-jump-to-message.png |
| 9 | Closed room "Secret Plans" with Jason: it appears in B's sidebar live. A→B direct message: `/rooms/directs` with Jason reuses the existing DM 186869642 (find-or-create, as Rails). B's DM entry gets `unread` live and the message shows. A brand-new DM with Lonely Lou from the sidebar "Start a ping" form is also created. | PASS (see U1) | 09-closed-room-form.png, 09-closed-room-created.png, 09-jason-sees-new-room-live.png, 09-direct-room-david.png, 09-jason-direct-unread-live.png, 09-jason-direct-room.png, 09-new-direct-room-lou.png, 09-new-ping-autocomplete.png, ref-09-new-ping-autocomplete.png |
| 10 | Profile: name changed to "David Renamed" and avatar to earth.png (both checked in the DB). Account settings: rename saved; Kevin promoted to admin and demoted again (role 1 then 0 in DB). Bots page lists the bots with curl commands. A bot created through the form ("E2E Bot") appears in the list. Posting with the Deploy Bot key via curl returns 201 and B sees the message live. Custom styles page opens. | PASS (see U3) | 10-profile*.png, 10-account-settings*.png, 10-account-kevin-promoted.png, 10-bots.png, 10-bot-new.png, 10-bot-created.png, 10-bot-message-live-for-jason.png, 10-custom-styles.png, 10-jason-sees-new-name-avatar.png; ref-10-* |
| 11 | Log out goes to `/session/new`. Visiting a room while signed out redirects to sign-in. A wrong password gets POST `/session` 401, and the body has the `panel shake` class, the flash "Too many requests or unauthorized." and the email kept, as on Rails. Signing in again works. | PASS (see U4) | 11-signed-out-sign-in-page.png, 11-wrong-password.png, ref-11-wrong-password.png, 11-signed-in-again.png |

### Extra parity checks

- **Rendered room DOM**, as seen by Jason. `dom-dump.sh` normalises the host, asset digests and `?v=`, then the two apps are diffed:
  - **Designers** (31 seeded messages: code, tables, sounds, opengraph, attachments, mentions, boosts): **0 differing lines**.
  - **All Talk** (131 messages): **0 differing lines** once author names are ignored. The only difference is the renamed author name, explained by cache warmth (U3).
- **Server logs** (`docker logs cfs-e2e`): no errors, exceptions or failed jobs during the run.
- **Repeated page loads**: 10 consecutive loads of HQ (`reload-probe.sh`) produced no new page errors on either app.

## JavaScript errors

The gate is `tools/e2e/errors.sh`: page errors (uncaught exceptions and rejections) plus console errors and warnings.

| Session | Error | Cause |
|---|---|---|
| cfe2e-a | `SyntaxError ... Identifier 'o' has already been declared` | Caused by this test: an injected WebSocket-logging probe was run twice. Not an app error. |
| cfe2e-a | `Uncaught (in promise) SyntaxError: Unexpected token '<', "<lexxy-pro"... is not valid JSON` | Comes from the New Ping recipient autocomplete. **Rails raises the identical error** (cfr-a). See U1. |
| cfe2e-a | `AbortError: signal is aborted without reason` (Turbo `FrameElement.reload` from `rooms_list_controller#channelConnected`) | Seen once. The sidebar frame was reloaded on cable connect while its lazy load was still in flight, so Turbo cancelled the first fetch. Rails makes the same double `/users/me/sidebar` fetch on every page load. Not reproduced in 10 further loads. A timing race in the unchanged frontend. Low severity, nothing to fix in the port. |
| cfe2e-b, cfr-b | none | |

Console errors and warnings: none in any session.

**JS gate: no Symfony-only JS errors.** The only app-caused errors are also present on Rails or are a timing race in the unchanged frontend.

## Real bugs in the port

None found. Everything that looked wrong was reproduced on the Rails reference with the same
markup or the same HTTP exchange. These are listed below so nobody "fixes" them in the port
without fixing the reference too.

## Upstream behaviour (same on Rails; not port bugs)

Most significant first.

- **U1. New Ping recipient autocomplete shows no suggestions.**
  - Cause: `lib/autocomplete/base_autocomplete_handler.js` calls the native `fetch(url, { as: "json" })`. The `as` option is ignored, so the request goes out with `Accept: */*`. `Autocompletable::UsersController` lists `format.html` first and answers with `<lexxy-prompt-item>` HTML. `response.json()` then rejects.
  - Same on both apps: identical requests, response and uncaught error.
  - Workaround used here: the direct room was created through the real `/rooms/directs` form, with the user option added to the hidden `<select>`, and through the sidebar "Start a ping" buttons.
- **U2. Messages that mention the viewer do not get `message--mentioned`.**
  - Cause: `message_formatter.js` looks for `.mention img[src^="/users/<id>/avatar"]`, but `avatar_tag` uses the signed avatar token path (`/users/<token>/avatar`), so the selector never matches.
  - Same on both apps. The mention still shows as a highlighted chip with avatar and bold name.
- **U3. After a rename or avatar change, already-cached messages keep the old name and avatar version.**
  - Cause: the fragment cache key is `[message, "presentation-v3"]`, which does not include the author. The sidebar DM label ("Ping with David") is also stale.
  - Same on both apps: Rails HQ showed "David" with the old `?v=` too. Messages rendered for the first time after the rename show the new name on both.
- **U4. A wrong password shows no visible error in the browser.**
  - Cause: the 401 page carries the flash and `shake`, but `sessions/new` declares `turbo_page_requires_reload`, so Turbo reloads `/session/new` with a GET and the flash and email are lost.
  - Same on both apps (POST 401, then GET 200). Without JS the error is rendered (checked with curl on both apps).
- **U5. The typing indicator stays visible on B for about 5–6 s after A sends.**
  - Cause: A does send `stop` (logged on the WebSocket), but a throttled `start` from the same keystroke burst can arrive after it. The indicator then clears on `TypingTracker`'s 5 s timeout.
  - Measured with `typing-probe.sh`: about 6 s on Symfony and 5–6 s on Rails.

## Tooling notes

- On this machine, agent-browser `fill` sometimes typed in front of the existing value instead of replacing it. This affected the profile name field (it briefly saved "David RenamedDavid"). Values were then set by clearing the field and typing.
- agent-browser `errors --clear` does not clear the buffer, so `errors.sh` reports all errors since the browser started.
- `Meta+a` does not select all in headless Chromium.

## Cleanup

- Both containers (`cfs-e2e`, `cfr-e2e`) and the three volumes (`cfs-e2e-seed`, `cfs-e2e-fresh`, `cfr-e2e-seed`) were removed.
- All four browser sessions were closed.
- No application code was changed and nothing was committed.
