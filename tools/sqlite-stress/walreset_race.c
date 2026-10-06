/*
 * Deterministic reproducer of SQLite's WAL-reset bug (https://sqlite.org/wal.html#walresetbug,
 * 3.7.0 through 3.51.2; fixed in 3.51.3, 3.50.7 and 3.44.6) against whatever libsqlite3.so.0 the
 * loader picks. A shim VFS pauses the checkpointing connection at the point the race needs, so
 * the interleaving that happens by chance under load (FrankenPHP worker threads committing and
 * auto-checkpointing at the same instant) happens every time:
 *
 *   1. the WAL holds M frames, all checkpointed (nBackfill == mxFrame == M);
 *   2. connection C starts a PASSIVE checkpoint: it takes the checkpoint lock and reads the
 *      wal-index header (mxFrame = M). C is paused in the xUnfetch that follows (with mmap on,
 *      the munmap of its stale mapping);
 *   3. connection W commits a small transaction: it resets the WAL (nBackfill = 0, new salts)
 *      and writes k < M frames at the start of the WAL;
 *   4. C resumes with its stale header: nBackfill (0) < mxFrame (M), so it checkpoints "frames
 *      1..M" and sets nBackfill = M although the WAL only holds k valid frames;
 *   5. more commits append frames k+1..; the next checkpoint skips every frame <= M, so those
 *      pages never reach the database file.
 *
 * Fixed libraries notice the salt change (step 4) and skip the bogus checkpoint.
 *
 *   cc -O1 -o walreset_race walreset_race.c -l:libsqlite3.so.0 -lpthread   (sqlite3.h needed)
 *   ./walreset_race /path/to/scratch/dir
 *
 * Exits 0 when the database is intact, 1 when the race corrupted it, 2 when the interleaving
 * couldn't be set up.
 */
#include <errno.h>
#include <pthread.h>
#include <semaphore.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

#include "sqlite3.h"

static sqlite3_vfs *base;
static sqlite3_vfs ckpt_vfs, writer_vfs;

typedef struct ShimFile {
  sqlite3_file file;
  sqlite3_file *real;
  int role; /* 1: checkpointer's db file, 2: writer's WAL file, 0: anything else */
} ShimFile;

static sem_t sem_start_writer, sem_committed;
static volatile int armed;        /* C holds the checkpoint lock in the checkpoint under test */
static volatile int paused_unfetch, writer_reset;
static int timed_out;

static void wait_sem(sem_t *s, const char *what) {
  struct timespec ts;
  clock_gettime(CLOCK_REALTIME, &ts);
  ts.tv_sec += 3;
  while (sem_timedwait(s, &ts) != 0) {
    if (errno == ETIMEDOUT) {
      fprintf(stderr, "timeout waiting for %s\n", what);
      timed_out = 1;
      return;
    }
  }
}

#define REAL(f) (((ShimFile *)(f))->real)
static int xClose(sqlite3_file *f) { int rc = REAL(f)->pMethods->xClose(REAL(f)); sqlite3_free(REAL(f)); return rc; }
static int xRead(sqlite3_file *f, void *b, int n, sqlite3_int64 o) { return REAL(f)->pMethods->xRead(REAL(f), b, n, o); }
static int xWrite(sqlite3_file *f, const void *b, int n, sqlite3_int64 o) {
  ShimFile *p = (ShimFile *)f;
  if (p->role == 2 && o == 0 && armed) {
    writer_reset = 1; /* W writes the header of a reset WAL: nBackfill = 0, new salts */
  }
  return REAL(f)->pMethods->xWrite(REAL(f), b, n, o);
}
static int xTruncate(sqlite3_file *f, sqlite3_int64 s) { return REAL(f)->pMethods->xTruncate(REAL(f), s); }
static int xSync(sqlite3_file *f, int fl) { return REAL(f)->pMethods->xSync(REAL(f), fl); }
static int xFileSize(sqlite3_file *f, sqlite3_int64 *s) { return REAL(f)->pMethods->xFileSize(REAL(f), s); }
static int xLock(sqlite3_file *f, int l) { return REAL(f)->pMethods->xLock(REAL(f), l); }
static int xUnlock(sqlite3_file *f, int l) { return REAL(f)->pMethods->xUnlock(REAL(f), l); }
static int xCheckReservedLock(sqlite3_file *f, int *r) { return REAL(f)->pMethods->xCheckReservedLock(REAL(f), r); }
static int xFileControl(sqlite3_file *f, int op, void *a) { return REAL(f)->pMethods->xFileControl(REAL(f), op, a); }
static int xSectorSize(sqlite3_file *f) { return REAL(f)->pMethods->xSectorSize(REAL(f)); }
static int xDeviceCharacteristics(sqlite3_file *f) { return REAL(f)->pMethods->xDeviceCharacteristics(REAL(f)); }
static int xShmMap(sqlite3_file *f, int i, int sz, int ext, void volatile **pp) { return REAL(f)->pMethods->xShmMap(REAL(f), i, sz, ext, pp); }
static int xShmLock(sqlite3_file *f, int ofst, int n, int flags) {
  ShimFile *p = (ShimFile *)f;
  int rc = REAL(f)->pMethods->xShmLock(REAL(f), ofst, n, flags);
  if (p->role == 1 && ofst == 1 && n == 1 && rc == SQLITE_OK && flags == (SQLITE_SHM_LOCK | SQLITE_SHM_EXCLUSIVE) && armed == 1) {
    armed = 2; /* WAL_CKPT_LOCK taken by the checkpoint under test */
  }
  return rc;
}
static void xShmBarrier(sqlite3_file *f) { REAL(f)->pMethods->xShmBarrier(REAL(f)); }
static int xShmUnmap(sqlite3_file *f, int d) { return REAL(f)->pMethods->xShmUnmap(REAL(f), d); }
static int xFetch(sqlite3_file *f, sqlite3_int64 o, int n, void **pp) { return REAL(f)->pMethods->xFetch(REAL(f), o, n, pp); }
static int xUnfetch(sqlite3_file *f, sqlite3_int64 o, void *p) {
  ShimFile *s = (ShimFile *)f;
  if (s->role == 1 && armed == 2 && o == 0 && p == 0 && !paused_unfetch) {
    /* C has read the wal-index header (mxFrame = M) and found it changed. W commits now,
     * resetting the WAL, before C goes on with that header. */
    paused_unfetch = 1;
    sem_post(&sem_start_writer);
    wait_sem(&sem_committed, "writer to commit");
  }
  return REAL(f)->pMethods->xUnfetch(REAL(f), o, p);
}

static const sqlite3_io_methods shim_methods = {
  3, xClose, xRead, xWrite, xTruncate, xSync, xFileSize, xLock, xUnlock, xCheckReservedLock,
  xFileControl, xSectorSize, xDeviceCharacteristics, xShmMap, xShmLock, xShmBarrier, xShmUnmap,
  xFetch, xUnfetch,
};

static int shim_open(sqlite3_vfs *vfs, sqlite3_filename name, sqlite3_file *f, int flags, int *out) {
  ShimFile *p = (ShimFile *)f;
  p->real = sqlite3_malloc(base->szOsFile);
  memset(p->real, 0, base->szOsFile);
  int rc = base->xOpen(base, name, p->real, flags, out);
  if (rc != SQLITE_OK) {
    sqlite3_free(p->real);
    f->pMethods = 0;
    return rc;
  }
  p->role = 0;
  if (vfs == &ckpt_vfs && (flags & SQLITE_OPEN_MAIN_DB)) p->role = 1;
  if (vfs == &writer_vfs && (flags & SQLITE_OPEN_WAL)) p->role = 2;
  f->pMethods = &shim_methods;
  return SQLITE_OK;
}

static void exec(sqlite3 *db, const char *sql) {
  char *err = 0;
  if (sqlite3_exec(db, sql, 0, 0, &err) != SQLITE_OK) {
    fprintf(stderr, "%s: %s\n", sql, err);
    exit(2);
  }
}

static sqlite3 *open_db(const char *path, const char *vfs) {
  sqlite3 *db;
  if (sqlite3_open_v2(path, &db, SQLITE_OPEN_READWRITE | SQLITE_OPEN_CREATE | SQLITE_OPEN_FULLMUTEX, vfs) != SQLITE_OK) {
    fprintf(stderr, "open %s: %s\n", path, sqlite3_errmsg(db));
    exit(2);
  }
  /* The app's PRAGMAs (src/Database/SqliteMiddleware.php), without automatic checkpoints so
   * that only the checkpoints below run. */
  exec(db, "PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA mmap_size = 134217728;"
           "PRAGMA busy_timeout = 5000; PRAGMA wal_autocheckpoint = 0");
  return db;
}

static sqlite3 *writer;
static void *writer_thread(void *arg) {
  (void)arg;
  wait_sem(&sem_start_writer, "checkpointer to read the WAL header");
  if (timed_out) return 0;
  /* k frames, fewer than the M frames of the previous WAL generation. */
  exec(writer, "BEGIN IMMEDIATE; WITH RECURSIVE s(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM s WHERE i < 5) INSERT INTO t (payload) SELECT randomblob(3000) FROM s; COMMIT");
  sem_post(&sem_committed);
  return 0;
}

int main(int argc, char **argv) {
  const char *dir = argc > 1 ? argv[1] : ".";
  char path[4096];
  snprintf(path, sizeof path, "%s/walreset-race.sqlite3", dir);
  for (const char *sfx = ""; ; sfx = sfx[0] == 0 ? "-wal" : (strcmp(sfx, "-wal") == 0 ? "-shm" : NULL)) {
    if (!sfx) break;
    char p[4200];
    snprintf(p, sizeof p, "%s%s", path, sfx);
    unlink(p);
  }

  base = sqlite3_vfs_find(0);
  ckpt_vfs = *base;
  ckpt_vfs.zName = "shim-ckpt";
  ckpt_vfs.szOsFile = sizeof(ShimFile);
  ckpt_vfs.xOpen = shim_open;
  ckpt_vfs.pNext = 0;
  writer_vfs = ckpt_vfs;
  writer_vfs.zName = "shim-writer";
  sqlite3_vfs_register(&ckpt_vfs, 0);
  sqlite3_vfs_register(&writer_vfs, 0);
  sem_init(&sem_start_writer, 0, 0);
  sem_init(&sem_committed, 0, 0);

  printf("sqlite %s\n", sqlite3_libversion());
  sqlite3 *setup = open_db(path, 0);
  exec(setup, "CREATE TABLE t (id INTEGER PRIMARY KEY, payload BLOB); CREATE INDEX t_payload ON t (length(payload))");
  exec(setup, "WITH RECURSIVE s(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM s WHERE i < 400) INSERT INTO t (payload) SELECT randomblob(3000) FROM s");
  exec(setup, "PRAGMA wal_checkpoint(TRUNCATE)");

  sqlite3 *ckpt = open_db(path, "shim-ckpt");
  exec(ckpt, "SELECT count(*) FROM t"); /* C caches the wal-index header now... */
  /* ...which these M frames make stale, before a checkpoint copies them all. */
  exec(setup, "WITH RECURSIVE s(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM s WHERE i < 200) INSERT INTO t (payload) SELECT randomblob(3000) FROM s");
  exec(setup, "UPDATE t SET payload = randomblob(3000) WHERE id % 3 = 0");
  int nLog = 0, nCkpt = 0;
  sqlite3_wal_checkpoint_v2(setup, 0, SQLITE_CHECKPOINT_PASSIVE, &nLog, &nCkpt);
  printf("WAL generation 1: %d frames, %d checkpointed\n", nLog, nCkpt);
  if (nLog <= 0 || nLog != nCkpt) return 2;

  writer = open_db(path, "shim-writer");
  pthread_t w;
  pthread_create(&w, 0, writer_thread, 0);
  armed = 1;
  int rc = sqlite3_wal_checkpoint_v2(ckpt, 0, SQLITE_CHECKPOINT_PASSIVE, &nLog, &nCkpt);
  armed = 0;
  pthread_join(w, 0);
  printf("racing checkpoint: rc %d, WAL %d frames, %d checkpointed (checkpointer paused %d, WAL reset by the writer %d)\n",
         rc, nLog, nCkpt, paused_unfetch, writer_reset);
  if (timed_out || !paused_unfetch || !writer_reset) {
    printf("could not set up the interleaving\n");
    return 2;
  }

  /* Step 5: the WAL grows past M frames, and a checkpoint copies "the rest". */
  char *err = 0;
  if (sqlite3_exec(setup, "WITH RECURSIVE s(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM s WHERE i < 600) INSERT INTO t (payload) SELECT randomblob(3000) FROM s", 0, 0, &err) != SQLITE_OK) {
    printf("next write: %s\nCORRUPT\n", err);
    return 1;
  }
  sqlite3_wal_checkpoint_v2(setup, 0, SQLITE_CHECKPOINT_TRUNCATE, &nLog, &nCkpt);
  sqlite3_close(writer);
  sqlite3_close(ckpt);
  sqlite3_close(setup);

  /* A fresh connection reads the database file alone (the WAL was truncated). */
  sqlite3 *check = open_db(path, 0);
  sqlite3_stmt *st;
  int bad = 0, n = 0;
  sqlite3_prepare_v2(check, "PRAGMA integrity_check", -1, &st, 0);
  while ((rc = sqlite3_step(st)) == SQLITE_ROW) {
    const char *r = (const char *)sqlite3_column_text(st, 0);
    if (strcmp(r, "ok") != 0) bad = 1;
    if (n++ < 5) printf("integrity_check: %s\n", r);
  }
  if (rc != SQLITE_DONE) {
    printf("integrity_check: %s\n", sqlite3_errmsg(check));
    bad = 1;
  }
  sqlite3_finalize(st);
  if (sqlite3_prepare_v2(check, "SELECT count(*), sum(length(payload)) FROM t", -1, &st, 0) == SQLITE_OK && sqlite3_step(st) == SQLITE_ROW) {
    printf("rows: %d (expected 1205)\n", sqlite3_column_int(st, 0));
    if (sqlite3_column_int(st, 0) != 1205) bad = 1;
  } else {
    printf("count: %s\n", sqlite3_errmsg(check));
    bad = 1;
  }
  sqlite3_finalize(st);
  sqlite3_close(check);
  printf(bad ? "CORRUPT\n" : "intact\n");
  return bad ? 1 : 0;
}
