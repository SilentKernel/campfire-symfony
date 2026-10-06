#!/usr/bin/env bash
# Cross-runtime check (Rails <-> Symfony on one shared storage volume). See crossruntime.py.
#   tools/crossruntime/run.sh            # full run, removes containers + volumes at the end
#   tools/crossruntime/run.sh --keep     # keep the xrt-storage volume for inspection
#   tools/crossruntime/run.sh --rebuild  # rebuild campfire-symfony:app first
set -euo pipefail
cd "$(dirname "$0")/../.."
args=()
for a in "$@"; do
  if [ "$a" = "--rebuild" ]; then docker build -t campfire-symfony:app .; else args+=("$a"); fi
done
exec python3 tools/crossruntime/crossruntime.py ${args[@]+"${args[@]}"}
