#!/usr/bin/env bash
# Prints the page errors and console errors/warnings captured by both browser sessions.
#   E2E_PREFIX=cfe2e errors.sh      (exit 1 when any page error was captured)
here="$(cd "$(dirname "$0")" && pwd)"
status=0
for s in a b; do
  out=$("$here/ab-$s" errors --json; echo; "$here/ab-$s" console --json)
  echo "$out" | python3 -c '
import json, sys
name = sys.argv[1]
errors, console = [], []
for line in sys.stdin:
    line = line.strip()
    if not line.startswith("{"): continue
    d = (json.loads(line).get("data") or {})
    errors += d.get("errors") or []
    console += [m for m in d.get("messages") or [] if m.get("type") in ("error", "warning")]
print(f"== session {name}: {len(errors)} page error(s), {len(console)} console error/warning(s)")
for e in errors: print("  ERROR:", e.get("text", "").splitlines()[0][:300])
for m in console: print("  CONSOLE", m.get("type"), ":", m.get("text", "")[:300])
sys.exit(1 if errors else 0)
' "${E2E_PREFIX:-cfe2e}-$s" || status=1
done
exit $status
