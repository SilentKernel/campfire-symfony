# syntax = docker/dockerfile:1
#
# A Linux toolbox for the benchmark and parity scripts, which need GNU userland (bash 4+,
# mapfile, taskset, cp --reflink, /proc and cgroup v2) that macOS doesn't have. It drives the
# host's Docker engine through its socket; started by bench/bin/runner.
FROM rust:1-trixie

RUN apt-get update -qq && \
    apt-get install --no-install-recommends -y \
      bash coreutils util-linux procps python3 curl git jq sqlite3 ca-certificates docker-cli docker-buildx && \
    rm -rf /var/lib/apt/lists /var/cache/apt/archives

ENV CARGO_HOME=/opt/campfire-bench/cargo
WORKDIR /opt/campfire-bench
