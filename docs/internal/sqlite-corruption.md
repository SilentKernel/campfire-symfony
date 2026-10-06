# "database disk image is malformed" under concurrent posting (2026-10-05)

## Symptom

In the full benchmark (`bench/results/2026-10-05-m3pro`), Symfony rep 4 returned 46 HTTP 500s
during `post_message` at c=16 (12,776 × 200, 46 × 500). Every 500 was
`SQLSTATE[HY000]: General error: 11 database disk image is malformed`. All 46 fell within 35 ms
(20:18:03.716–.750). The c=64 run that came next, and every later suite, had no errors. Reps 1,
2, 3 and 5 had none either. Rails, Django and Laravel ran on the same filesystem in the same run
and had none.

Each failing request logged `Sending message App\Job\PushMessageJob` (the jobs DB insert,
`Room#receive` after commit) just before the exception. The exception was a bare
`Doctrine\DBAL\Exception\DriverException`. A failure inside the Messenger send would have been
wrapped in a `TransportException` (`DoctrineSender::send`). So the failing statement was a read
of the **Rails database** made after the message committed: rendering the broadcast, the
memberships fan-out, the bot lookup or the response.

## Root cause: SQLite's WAL-reset bug, in Debian's libsqlite3 3.46.1

The image used Debian trixie's `libsqlite3-0` 3.46.1-7+deb13u2. Every process in the image
(FrankenPHP's `pdo_sqlite`, `campfire:cable`, `messenger:consume`, the `sqlite3` CLI) linked it.
That version has the **WAL-reset bug** (<https://sqlite.org/wal.html#walresetbug>). The bug is
present in 3.7.0 through 3.51.2 and fixed in 3.51.3, with backports in 3.50.7 and 3.44.6. Debian
has not backported the fix to trixie (its +deb13u1/u2 changes are FTS5 CVE fixes only). Rails'
`sqlite3` gem 2.9.6 bundles SQLite **3.53.2**, which has the fix.

How the race goes (sqlite.org's steps, and `tools/sqlite-stress/walreset_race.c`):

1. A checkpoint copies the whole WAL into the database (`nBackfill == mxFrame == M`).
2. Another connection starts a PASSIVE checkpoint. It takes the checkpoint lock and reads the
   wal-index header (`mxFrame = M`). Its cached header was stale, so it then calls
   `xUnfetch(0)`, which with `mmap_size > 0` is a `munmap` of its 128 MB mapping.
3. During that window, a third connection commits. Because the WAL was fully checkpointed, the
   commit **resets** the WAL (`nBackfill = 0`, new salts) and writes k < M frames from the start.
4. The checkpointer resumes with its stale header. Since `nBackfill (0) < mxFrame (M)`, it
   "checkpoints" frames 1..M and sets `nBackfill = M`. The WAL holds only k valid frames.
5. Later commits append frames k+1... The next checkpoint skips every frame ≤ M, so those pages
   never reach the database file. Readers that later read those pages from the file get stale
   b-tree pages, and SQLite reports `SQLITE_CORRUPT` ("database disk image is malformed").

The 3.51.3 fix compares the WAL salts after taking `WAL_READ_LOCK(0)` and abandons the checkpoint
if the WAL was reset in the meantime.

Why this app, and why so rarely:

- The race needs one connection to commit while another starts a checkpoint on a fully
  checkpointed WAL. FrankenPHP runs 8 worker threads (2 × 4 CPUs), each with its own connection,
  plus `campfire:cable` and `messenger:consume`. Under `post_message` every commit is a small
  write transaction (message, rich text, room touch), followed by FTS and membership writes in
  autocommit. Every commit that leaves the WAL at 1000 frames or more runs an auto-checkpoint. That
  includes the commits racing in right after a full checkpoint, before the next writer resets
  the WAL. So checkpoints routinely start on a fully checkpointed WAL while other threads commit.
- `mmap_size` (Rails' PRAGMA) puts a `munmap` inside the race window. In a multithreaded process,
  that munmap needs a TLB shootdown, and with 8+ PHP threads on 4 CPUs the thread can be
  preempted there. Single-threaded worker processes (Django's Uvicorn workers, Laravel's FPM
  children) run the same vulnerable versions (3.46.1, 3.40.1) but are not in this position as
  often. This explains the exposure; it is not separately measured.
- Most of the pages lost in step 5 are the hottest ones: the rightmost leaves of `messages`,
  `action_text_rich_texts`, the indexes and the FTS segments. The next commits rewrite them and
  the next checkpoint copies those versions. This is why the errors came as one 35 ms burst and
  the database then served c=64 cleanly. A page that was lost and never rewritten would have
  stayed corrupt. The bench deletes its work copy, so that rep's database was never checked.

## Hypotheses ruled out (evidence from the running container)

| Hypothesis | Evidence |
|---|---|
| Two SQLite copies in one process (pdo_sqlite vs sqlite3 vs FFI/libvips) | `/proc/<frankenphp>/maps` showed a single `libsqlite3.so.0.8.6`. pdo_sqlite and sqlite3 are compiled into `libphp.so`, which links `libsqlite3.so.0` dynamically, and the frankenphp binary carries no SQLite symbols of its own. libvips doesn't link SQLite. Only its openslide module does, and it uses the same soname, so it gets the copy already loaded. Exactly one file in the image defines `sqlite3_open_v2`. |
| SQLite not thread-safe | `PRAGMA compile_options`: `THREADSAFE=1`, `MUTEX_PTHREADS`. Each PHP thread has its own container and so its own PDO connection. No connection or statement is shared. |
| Per-request reconnects / fcntl lock loss | The 8 main-DB fds in the FrankenPHP process stay the same across load. With one library, SQLite's per-inode lock table keeps POSIX locks across closes anyway. Connections *are* reopened when a worker thread restarts, which Symfony Runtime does every 500 requests by default (`FRANKENPHP_LOOP_MAX`). Within one library that churn is safe. |
| mmap/overlayfs, journal_size_limit, jobs DB, FTS5 | Rails uses the same PRAGMAs on the same filesystem. The failing statement was in the Rails database, not the jobs DB. 470,000 posts on the old image ended with `integrity_check` ok and the FTS5 `integrity-check` ok. |

## Reproduction and proof

- `tools/sqlite-stress/walreset_race.c` forces the interleaving with a shim VFS: the
  checkpointer pauses in `xUnfetch` while a writer commits. It corrupts the database on every
  run with Debian's 3.46.1 (`racing checkpoint: … 409 checkpointed`, then `next write: database
  disk image is malformed`, 5/5 runs). With 3.53.4 it leaves the database intact
  (`… 0 checkpointed`, `integrity_check: ok`, all 1205 rows, 5/5 runs).
- `tools/sqlite-stress/stress.sh` is the app-level stress test. It runs bench/run's setup (seed
  copy on the VM filesystem, cpuset 0-3, `--network host`, cable and messenger running), then
  rounds of loadgen `POST /rooms/<hq>/messages` at c=16 and c=64, with room-page and search GETs
  at c=8 each, then both integrity checks. Results:

  | Library | Run | Requests (posts) | 5xx / malformed | integrity_check, FTS5 |
  |---|---|---|---|---|
  | Debian 3.46.1 (old image) | 40 × (c=16 + c=64) × 8 s, GETs mixed in | 698,201 (≈468,000) | 0 / 0 | ok |
  | Debian 3.46.1 (`LD_LIBRARY_PATH` on the new image) | 40 × c=16 × 8 s, posts only | 503,094 | 0 / 0 | ok |
  | 3.53.4 (new image) | 40 × (c=16 + c=64) × 8 s, GETs mixed in | 839,912 (≈569,000) | 0 / 0 | ok |

  The organic race did not trigger in about 1.2 million requests on the vulnerable library. In
  the benchmark it happened once in five reps, with roughly 30k posts per rep. With the run sizes
  above, a before/after failure rate from the full stack can't tell the two libraries apart. The
  deterministic reproducer is the proof. The stress test is the full-stack regression check, and
  it shows the new library causes no regression: 5,353 / 9,543 posts per 8 s round at c=16 / 64,
  against 5,241 / 9,362 before.

## Fix

The image builds SQLite **3.53.4** from the official amalgamation (SHA3-256 checked). It uses
Debian's compile options (`THREADSAFE=1`, FTS3/4/5, RTREE, session, dbstat, dbpage, column
metadata, `SECURE_DELETE`, `USE_URI`, …) and installs to `/usr/local/lib/<multiarch>`, which
`/etc/ld.so.conf.d/<multiarch>.conf` lists before Debian's directory. The soname is the same
(`libsqlite3.so.0`), so pdo_sqlite, the sqlite3 extension, libvips' openslide module and the
`sqlite3` CLI used by the ONCE hooks all load the one fixed library. The Dockerfile's `base`
stage fails the build unless PHP reports ≥ 3.51.3. The PRAGMAs are unchanged, so they still
match Rails (including `mmap_size`).

Regression checks: `tests/Container/sqlite.sh`, also run by `tests/Container/smoke.sh`. It checks
one SQLite version across pdo_sqlite, sqlite3 and the CLI, ≥ 3.51.3, `THREADSAFE=1`, FTS5, and
exactly one `libsqlite3` mapped in the running frankenphp process. It also checks that the race
reproducer corrupts with Debian's library (so the test has teeth) and not with the image's.
`tools/sqlite-stress/stress.sh` is the end-to-end stress run.
