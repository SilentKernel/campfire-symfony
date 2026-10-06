#!/usr/bin/env bash
# Regenerates tests/fixtures/richtext/canonical.json with the real Rails pipeline in the reference
# image (campfire-reference:app), using the parity SECRET_KEY_BASE the corpus SGIDs were signed with.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
docker run --rm \
  -e RAILS_ENV=production \
  -e SECRET_KEY_BASE=5335c3b1ad35b4ad170c3413bd651ef3b6ed64e257261871a6de3f978cf3868ee417a927040935fb30b0f7debdedb34a2a403e9f34b16cf594c917c2ecd4a995 \
  -e DISABLE_SSL=true -e SKIP_TELEMETRY=true -e RAILS_LOG_LEVEL=error \
  -e DATABASE_URL=sqlite3:/tmp/richtext.sqlite3 \
  -v "$here:/corpus" \
  --user "$(id -u):$(id -g)" \
  campfire-reference:app \
  bash -c "bin/rails db:schema:load >/dev/null && bin/rails runner /corpus/generate_canonical.rb"
