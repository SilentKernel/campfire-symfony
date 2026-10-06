#!/usr/bin/env bash
# Regenerates tests/fixtures/richtext/seed.json by running the Rails app (campfire-reference:app)
# over a copy of the default seed database, with the parity SECRET_KEY_BASE the seed was built with.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/db"
cp "$root/var/seed/default/db/production.sqlite3" "$work/db/production.sqlite3"
docker run --rm \
  -e RAILS_ENV=production \
  -e SECRET_KEY_BASE=5335c3b1ad35b4ad170c3413bd651ef3b6ed64e257261871a6de3f978cf3868ee417a927040935fb30b0f7debdedb34a2a403e9f34b16cf594c917c2ecd4a995 \
  -e DISABLE_SSL=true -e SKIP_TELEMETRY=true -e RAILS_LOG_LEVEL=error \
  -v "$work/db:/rails/storage/db" \
  -v "$here:/out" \
  --user "$(id -u):$(id -g)" \
  campfire-reference:app \
  bin/rails runner /out/generate_seed.rb
