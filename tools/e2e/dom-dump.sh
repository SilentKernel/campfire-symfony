#!/usr/bin/env bash
# Dumps the rendered, normalized #messages markup of a room as seen in session B, for diffing the
# Symfony port against the Rails reference.
#   E2E_PREFIX=cfe2e dom-dump.sh <base-url> <room-id> <out-file>
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
B="$here/ab-b"
"$B" open "$1/rooms/$2" >/dev/null
sleep 2.5
"$B" eval '(() => {
  const root = document.querySelector("[id^=messages_rooms_]").cloneNode(true)
  return root.outerHTML
})()' | python3 -c '
import json, re, sys
html = json.loads(sys.stdin.read())
html = re.sub(r"\?v=\d+", "?v=N", html)
html = re.sub(r"/assets/([\w/.-]+?)-[\w-]{7,8}\.(svg|png|js|css)", r"/assets/\1.\2", html)
html = re.sub(r"name=\"authenticity_token\" value=\"[^\"]+\"", "name=\"authenticity_token\" value=\"T\"", html)
html = re.sub(r"message_[a-z0-9]{8,12}\b", "message_X", html)
html = re.sub(r">\s+<", ">\n<", html)
html = re.sub(r"[ \t]+", " ", html)
print(html)' > "$3"
wc -c "$3"
