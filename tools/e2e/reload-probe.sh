#!/usr/bin/env bash
# Opens <url> N times in session A and counts page errors (e.g. Turbo AbortError races).
#   E2E_PREFIX=cfe2e reload-probe.sh <url> [n]
here="$(cd "$(dirname "$0")" && pwd)"
A="$here/ab-a"
"$A" errors --clear >/dev/null 2>&1
for _ in $(seq 1 "${2:-10}"); do
  "$A" open "$1" >/dev/null
  sleep 1.5
done
"$A" errors --json | python3 -c '
import json, sys, collections
d = json.load(sys.stdin)["data"]["errors"]
c = collections.Counter(e["text"].splitlines()[0][:120] for e in d)
print(f"{len(d)} page error(s)"); [print(f"  {n} x {t}") for t, n in c.items()]'
