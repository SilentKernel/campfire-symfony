# Realtime: Action Cable

The browser runs the unchanged `@rails/actioncable` and `turbo-rails` JavaScript against `/cable`.
In Rails, Puma serves the cable and Redis distributes broadcasts. Here there are two parts:

- `bin/console campfire:cable` (`src/Command/CableCommand.php`, `src/Cable/Server/`): one PHP
  process running a Workerman event loop. It serves the WebSockets and keeps the subscription
  index.
- `App\Cable\SocketBroadcaster`: the publisher used in web requests, jobs and commands. It writes
  to the cable server over a unix socket.

Caddy proxies `/cable` to `127.0.0.1:$TARGET_PORT`. The publish socket is `var/run/cable.sock`
(`CAMPFIRE_CABLE_SOCKET`).

```
browser ──wss /cable──▶ Caddy ──▶ campfire:cable (127.0.0.1:TARGET_PORT)
                                        ▲
                                        │ unix socket, length-prefixed frames
web request / job ──after commit──▶ SocketBroadcaster
```

## Protocol

`actioncable-v1-json`, as `ActionCable::Connection::Base` speaks it (`Server/Protocol.php`,
`Server/Handshake.php`, `Server/WebSocket.php`):

- **Handshake.**
  - A plain HTTP upgrade. RFC 6455 key and version 13 are required.
  - Sub-protocol `actioncable-v1-json` (or `actioncable-unsupported`) is echoed when offered.
  - A non-WebSocket request gets `404 Page not found`, as Rails does. A malformed one gets 400.
    Headers are limited to 16 KB.
- **Origin.** The `Origin` header must equal `https://<Host>` (or `http://` with `DISABLE_SSL` and
  no forwarded-HTTPS header). This is Rails' `allow_same_origin_as_host`. The dev environment also
  allows `http(s)://localhost:<any port>`.
- **Authentication** (`ApplicationCable::Connection#connect`).
  - The signed `session_token` cookie from the handshake is verified with the same key as the web
    app.
  - The cookie must name a session whose user is active. Otherwise the server sends
    `{"type":"disconnect","reason":"unauthorized","reconnect":false}` and closes.
  - On success it sends `{"type":"welcome"}`.
- **Commands** (`subscribe`, `unsubscribe`, `message`):
  - They run one at a time in arrival order. Commands that arrive before authentication finishes
    wait.
  - Replies are `confirm_subscription` / `reject_subscription`. Broadcasts are
    `{"identifier":…,"message":…}`, with keys in the order Ruby's hashes produce them.
  - Malformed commands are logged and ignored, as in Rails. An unknown action logs
    `Unable to process`.
- **Heartbeat:** `{"type":"ping","message":<unix time>}` every 3 seconds. One frame is built and
  written to every open socket.
- **Remote disconnect:**
  - `{"type":"disconnect","reason":"remote","reconnect":…}`, then the socket closes and its
    channels unsubscribe.
  - Sent after commit when a user signs out, is banned or deactivated, or loses a membership.
    This is `close_remote_connections` / `reset_remote_connections` in Rails.
- **Restart:** on SIGTERM/SIGINT every client gets `server_restart` with `reconnect: true` before
  the process exits. Clients reconnect to the new process.
- **WebSocket frames:** ping/pong, close codes and fragmentation are handled. Text must be valid
  UTF-8 (close code 1007 otherwise).

## Channels

`Server/ChannelRegistry.php` maps the Ruby class name in the identifier to a channel instance, one
per subscription:

| Channel | Behaviour (as `reference/app/channels/…`) | Stream |
|---|---|---|
| `Turbo::StreamsChannel` | Verifies `signed_stream_name`. A room's `…:messages` stream is refused here (`RoomStreamsAreAuthorized`). | the verified name |
| `RoomMessagesChannel` | The verified stream must be `<room gid param>:messages` for a room the user is a member of. The room is located with `GlobalID` `only: Room`, so the STI class must match. | `<room gid>:messages` |
| `RoomChannel` | `current_user.rooms.find_by(id: room_id)`, else reject | `room:<room gid>` |
| `PresenceChannel` | RoomChannel + `present` on subscribe, `absent` on unsubscribe, actions `present`/`absent`/`refresh`. `present` marks the room read and broadcasts `{"room_id":…}` to the user's reads stream. | `presence:<room gid>` |
| `TypingNotificationsChannel` | RoomChannel + actions `start`/`stop`, broadcast as `{"action":…,"user":{"id":…,"name":…}}` | `typing_notifications:<room gid>` |
| `UnreadRoomsChannel` | per user | `user_<id>_unreads` |
| `ReadRoomsChannel` | per user | `user_<id>_reads` |
| `HeartbeatChannel`, `ApplicationCable::Channel` | subscribe and confirm only | — |

`<room gid>` is `room.to_gid_param`, URL-safe Base64 of `gid://campfire/Rooms::Open/<id>`. Stream
names are built by `App\Cable\StreamNames`.

The streams the app publishes to, with their Turbo stream or JSON payloads:

| Stream | Published by | Payload |
|---|---|---|
| `<room gid>:messages` | `Domain\Messages\MessageBroadcasts` | `append` a new message, `replace` an edited one, `remove` a deleted message or boost, `append` a boost |
| `user_<id>_unreads` | `MessageBroadcasts` (to every member, after a post) | `{"roomId":…}` |
| `rooms` | `Domain\Rooms\RoomBroadcasts` | sidebar `prepend`/`replace`/`remove` of shared rooms |
| `<user gid>:rooms` | `RoomBroadcasts` | per-user sidebar changes (direct rooms, rooms gained or lost) |
| `user_<id>_reads`, `presence:…`, `typing_notifications:…` | the cable server itself (channel actions) | JSON |

## Presence

Presence lives in the database, as in Rails (`Membership::Connectable`, `Server/CableRepository.php`):

- `memberships.connections` and `connected_at` are updated with the same statements and the same
  60-second TTL.
- When the cable server starts it runs `Membership.disconnect_all`.
- Because the data is in SQLite, presence works across processes, and even across runtimes. In
  the cross-runtime check, a user subscribed on one app kept a room read while posts arrived
  through the other app.

The cable server's database work is single short statements on its own DBAL connection, with a
20 ms busy timeout. When SQLite is locked by a writer, the command is retried with backoff (up to
250 ms between attempts, 60 attempts) instead of blocking the event loop. Commands behind it on
the same socket wait their turn. Authentication and unsubscribe callbacks of closed sockets are
retried the same way.

## Broadcaster

`SocketBroadcaster` implements `App\Cable\Broadcaster` (`broadcast($stream, $payload)`,
`disconnectUser($userId, $reconnect)`):

- **After commit.** Inside a transaction, a publication is queued with
  `Transactions::afterCommit()` and dropped on rollback, like Rails' `after_*_commit`
  broadcasts. Callers don't need to care.
- **Batching.** Publications are buffered and written together:
  - just before the HTTP response is sent (`BroadcastFlushListener` at the lowest priority), so
    clients receive the broadcast along with the response, as from Rails;
  - after each Messenger job;
  - when a console command ends;
  - on kernel reset;
  - once 64 KB are pending.
- **Wire format** (`ControlFrame`): a 4-byte big-endian length, then either
  `B <u16 stream length> <stream> <payload JSON>` or `D <u32 user id> <0|1 reconnect>`. Several
  frames travel in one write. The server applies every frame of one read as a batch, so a
  request's broadcasts reach each socket in one write.
- **Encoding.** Each broadcast is encoded and framed once per subscription identifier. All
  subscribers of a Turbo stream share one identifier, so the same bytes are written to every
  socket. Streams map directly to their subscriptions, so a broadcast costs nothing for sockets
  that don't follow it.
- **Connection.** The socket stays open across requests (worker mode) and reconnects lazily.
  Writes are non-blocking with a 250 ms deadline (50 ms to connect).
- **Failure behaviour.**
  - If the cable server is down or restarting, the publication is dropped. One warning is logged
    per second at most (`Action Cable server unreachable … broadcasts dropped`), and the request
    carries on. The broadcaster retries a second later.
  - In Rails, Redis pub/sub likewise drops broadcasts nobody is subscribed to.
  - Clients reconnect, and on reconnection the page requests `/rooms/:id/refresh`, which returns
    the messages it missed.
- **Tests.** The `test` environment uses `RecordingBroadcaster`, which records publications for
  assertions.

## Limits

Rails has none of these. They bound what one socket can make the server hold:

| Limit | Value | When exceeded |
|---|---|---|
| Subscriptions per connection | 64 | logged, subscription ignored |
| Identifier size | 4 KB | logged, subscription ignored |
| Message size (incl. fragments) | 1 MB | protocol error, close |
| Pending commands per connection | 256 | close with `reconnect: true` |
| Send buffer per client | 8 MB | a client this far behind is dropped; it reconnects and reloads |
| Handshake headers | 16 KB | 400 |

There is no `permessage-deflate`.

## Event loop

Workerman uses `ext-event` (libevent: epoll/kqueue) when the extension is loaded, and the image
ships it. Without the extension it falls back to `stream_select`, which can't watch file
descriptors past `FD_SETSIZE` (1024), so a dev host without `ext-event` handles about 1000
sockets. The process runs without Workerman's own master/worker supervisor; `bin/start`
restarts it.

## Measured fan-out

From one development run of the benchmark's cable suite (`bench/.work/w7-cable`, 2026-10-05). It
used 1 rep, an earlier image built during development (`campfire-symfony:w7`), 4 pinned vCPUs on
an Apple M3 Pro under OrbStack, and one room with chatter.js-style subscriptions per client. Every
message reached every client. The final multi-app numbers are in [benchmarks.md](benchmarks.md).

| Clients | Connect + subscribe all | Paced post → all clients, p50 / p99 | Sustained msgs/s delivered to all | Deliveries/s |
|---:|---:|---:|---:|---:|
| 100 | 0.07 s | 17.7 / 30.8 ms | 834 | 83,356 |
| 1,000 | 0.23 s | 33.2 / 43.3 ms | 161 | 161,288 |

Memory in that run, as Pss: the cable server plus FrankenPHP took 160 MB (100 idle clients) to
563 MB (1,000 clients under saturation). The whole container peaked at 594 MB.
