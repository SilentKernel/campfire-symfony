#!/usr/bin/env bash
# Creates the e2e Docker volumes: seeded copies for Symfony and Rails, and an empty one (first run).
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
for v in cfs-e2e-seed cfs-e2e-fresh cfr-e2e-seed; do
  docker volume rm -f "$v" >/dev/null 2>&1 || true
  docker volume create "$v" >/dev/null
done
for v in cfs-e2e-seed cfr-e2e-seed; do
  tar -C "$root/var/seed/default" -cf - db storage | docker run --rm -i -v "$v:/v" --user 0 alpine \
    sh -c 'cd /v && tar -xf - && mv storage files && chown -R 1000:1000 /v && ls /v /v/db'
done
docker run --rm -v cfs-e2e-fresh:/v --user 0 alpine chown -R 1000:1000 /v
