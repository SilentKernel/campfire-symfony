#!/usr/bin/env bash
# Submits the sign-in form with a wrong password in session B and reports what is on screen
# right after (flash, shake, email value) and the HTTP status of the POST.
#   E2E_PREFIX=cfe2e wrong-password.sh <base-url> <email> <screenshot.png>
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
B="$here/ab-b"
"$B" open "$1/session/new" >/dev/null
"$B" network requests --clear >/dev/null 2>&1
"$B" fill 'input[name="email_address"]' "$2" >/dev/null
"$B" fill 'input[name="password"]' wrongpassword >/dev/null
"$B" press Enter >/dev/null
sleep 0.4
"$B" screenshot "$3" >/dev/null
"$B" eval '(() => JSON.stringify({
  url: location.href,
  flash: document.querySelector(".flash")?.innerText.trim() ?? null,
  shake: !!document.querySelector(".panel.shake"),
  email: document.querySelector("input[name=email_address]")?.value
}))()'
"$B" network requests --filter session 2>&1 | tail -4
