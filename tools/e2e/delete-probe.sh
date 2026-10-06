#!/usr/bin/env bash
# Deletes message <dom-id> as user A through the edit form and measures when it disappears for B.
#   delete-probe.sh <message-dom-id>     (e.g. message_ru30ldoaosf)
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
A="$here/ab-a"; B="$here/ab-b"; M="#$1"
"$A" hover "$M" >/dev/null
"$A" click "$M .message__options-btn" >/dev/null
sleep 0.3
"$A" click "$M .message__edit-btn" >/dev/null
sleep 1.5
"$A" find role button click --name "Delete message" >/dev/null 2>&1 || true
"$A" dialog accept >/dev/null
start=$(python3 -c 'import time;print(time.time())')
for _ in $(seq 1 60); do
  gone_a=$("$A" eval "!document.querySelector('$M')")
  gone_b=$("$B" eval "!document.querySelector('$M')")
  now=$(python3 -c "import time;print(round(time.time()-$start,1))")
  echo "t=${now}s gone for A=$gone_a B=$gone_b"
  [ "$gone_b" = "true" ] && [ "$gone_a" = "true" ] && exit 0
  sleep 0.25
done
exit 1
