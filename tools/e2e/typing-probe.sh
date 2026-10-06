#!/usr/bin/env bash
# Measures how long user B keeps seeing A's typing indicator after A sends a message.
#   typing-probe.sh <base-url> <room-id> <text>
# Both sessions must be signed in and showing the room.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
A="$here/ab-a"; B="$here/ab-b"
"$A" click 'lexxy-editor' >/dev/null
"$A" keyboard type "$3" >/dev/null
sleep 1
echo "B indicator while typing: $("$B" eval 'document.querySelector(".typing-indicator").className.includes("--active")')"
"$A" press Enter >/dev/null
start=$(date +%s)
for i in $(seq 1 40); do
  active=$("$B" eval 'document.querySelector(".typing-indicator").className.includes("--active")')
  if [ "$active" = "false" ]; then echo "B indicator cleared after ~$(( $(date +%s) - start ))s"; exit 0; fi
  sleep 0.25
done
echo "B indicator still active after $(( $(date +%s) - start ))s"
