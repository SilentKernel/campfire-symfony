#!/usr/bin/env bash
# The SQLite library of the production image (docs/internal/sqlite-corruption.md):
#
#   * pdo_sqlite, the sqlite3 extension and the sqlite3 CLI all run one library, >= 3.51.3 (the
#     WAL-reset fix), thread-safe (SQLITE_THREADSAFE=1), with FTS5;
#   * a running FrankenPHP process maps exactly one libsqlite3 (two copies in one process don't
#     share SQLite's POSIX-lock bookkeeping);
#   * tools/sqlite-stress/walreset_race.c, which forces the WAL-reset race, corrupts a database
#     with Debian's library (so the test has teeth) and leaves it intact with the image's.
#
#   tests/Container/sqlite.sh                  campfire-symfony:app
#   SQLITE_IMAGE=campfire-symfony:smoke tests/Container/sqlite.sh
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
IMAGE=${SQLITE_IMAGE:-${SMOKE_IMAGE:-campfire-symfony:app}}
CONTAINER=campfire-symfony-sqlite-$$
failures=0
pass() { echo "  ok    $*"; }
fail() { echo "  FAIL  $*"; failures=$((failures + 1)); }
trap 'docker rm -f "$CONTAINER" >/dev/null 2>&1 || true' EXIT

echo "== sqlite ($IMAGE)"
versions=$(docker run --rm --entrypoint php "$IMAGE" -r '
  $pdo = new PDO("sqlite::memory:");
  $options = $pdo->query("PRAGMA compile_options")->fetchAll(PDO::FETCH_COLUMN);
  echo $pdo->query("select sqlite_version()")->fetchColumn(), " ", SQLite3::version()["versionString"], " ",
    in_array("THREADSAFE=1", $options, true) ? "threadsafe" : "NOT-threadsafe", " ",
    in_array("ENABLE_FTS5", $options, true) ? "fts5" : "no-fts5";')
read -r pdo ext threadsafe fts5 <<<"$versions"
cli=$(docker run --rm --entrypoint sqlite3 "$IMAGE" --version | cut -d' ' -f1)
[ "$pdo" = "$ext" ] && [ "$pdo" = "$cli" ] && pass "pdo_sqlite, sqlite3 and the CLI run SQLite $pdo" ||
  fail "SQLite versions differ: pdo_sqlite $pdo, sqlite3 $ext, CLI $cli"
php_ok=$(docker run --rm --entrypoint php "$IMAGE" -r "echo version_compare('$pdo', '3.51.3', '>=') ? 1 : 0;")
[ "$php_ok" = 1 ] && pass "SQLite $pdo has the WAL-reset fix (>= 3.51.3)" || fail "SQLite $pdo predates the WAL-reset fix (3.51.3)"
[ "$threadsafe" = threadsafe ] && pass "SQLITE_THREADSAFE=1" || fail "SQLite is not built thread-safe"
[ "$fts5" = fts5 ] && pass "FTS5 enabled" || fail "no FTS5"

docker run -d --name "$CONTAINER" -e SECRET_KEY_BASE=test -e DISABLE_SSL=1 -e HTTP_PORT=8080 "$IMAGE" >/dev/null
maps=""
for _ in $(seq 1 100); do
  maps=$(docker exec "$CONTAINER" bash -c '
    for d in /proc/[0-9]*; do
      case "$(tr "\0" " " < $d/cmdline 2>/dev/null)" in "frankenphp run"*)
        grep -i "libsqlite3" $d/maps | awk "{print \$6}" | sort -u; exit;;
      esac
    done' 2>/dev/null || true)
  [ -n "$maps" ] && break
  sleep 0.2
done
if [ "$(grep -c . <<<"$maps")" = 1 ] && [[ "$maps" = /usr/local/lib/* ]]; then
  pass "frankenphp maps one libsqlite3: $maps"
else
  fail "frankenphp's libsqlite3 mappings: ${maps:-none}"
fi
docker rm -f "$CONTAINER" >/dev/null

race=$(docker run --rm --user 0:0 --entrypoint bash -v "$ROOT/tools/sqlite-stress/walreset_race.c:/tmp/walreset_race.c:ro" "$IMAGE" -c '
  set -e
  cd /tmp && gcc -O1 -o walreset_race walreset_race.c -l:libsqlite3.so.0 -lpthread
  mkdir -p fixed debian
  ./walreset_race fixed > fixed.out 2>&1 && echo "fixed=0" || echo "fixed=$?"
  LD_LIBRARY_PATH=/usr/lib/$(gcc -dumpmachine) ./walreset_race debian > debian.out 2>&1 && echo "debian=0" || echo "debian=$?"
  head -1 debian.out | sed "s/^/debian: /"; tail -1 debian.out | sed "s/^/debian: /"
  head -1 fixed.out | sed "s/^/fixed: /"; tail -1 fixed.out | sed "s/^/fixed: /"')
grep -q '^debian=1$' <<<"$race" && pass "WAL-reset race corrupts a database with Debian's $(sed -n 's/^debian: sqlite //p' <<<"$race")" ||
  echo "  skip  the race reproducer did not corrupt Debian's library: $(tr '\n' ' ' <<<"$race")"
grep -q '^fixed=0$' <<<"$race" && pass "WAL-reset race leaves the database intact with the image's SQLite" ||
  fail "WAL-reset race with the image's SQLite: $(tr '\n' ' ' <<<"$race")"

if [ "$failures" -gt 0 ]; then
  echo "sqlite: $failures failure(s)"
  exit 1
fi
echo "sqlite: all checks passed"
