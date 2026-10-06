# syntax = docker/dockerfile:1
#
# Parity candidate image for campfire-symfony: the unmodified production image
# (campfire-symfony:app, built from /Dockerfile) plus what once-campfire-rust's parity harness needs
# around it. A copy of once-campfire-rust parity/docker/candidate/Dockerfile with the base image and
# CMD swapped; app code is untouched.
#
#   * libfaketime (same version, flags and checksum as the reference/Rust parity images): FAKETIME
#     fakes libc's clock (PHP's time(), SQLite's CURRENT_TIMESTAMP); CAMPFIRE_FROZEN_TIME freezes
#     the app's ClockInterface.
#   * procps for inspecting processes (sqlite3 is already in the base image)
#
# Built by tools/parity/bin/candidate build.

ARG BASE_IMAGE=campfire-symfony:app

FROM docker.io/library/debian:trixie-slim AS faketime
RUN apt-get update -qq && apt-get install --no-install-recommends -y build-essential ca-certificates curl
ARG FAKETIME_VERSION=0.9.10
ARG FAKETIME_SHA256=729ad33b9c750a50d9c68e97b90499680a74afd1568d859c574c0fe56fe7947f
RUN curl -fsSL -o /tmp/libfaketime.tar.gz https://github.com/wolfcw/libfaketime/archive/refs/tags/v${FAKETIME_VERSION}.tar.gz && \
    echo "${FAKETIME_SHA256}  /tmp/libfaketime.tar.gz" | sha256sum -c - && \
    tar xzf /tmp/libfaketime.tar.gz -C /tmp && \
    sed -i 's/-Werror //' /tmp/libfaketime-${FAKETIME_VERSION}/src/Makefile && \
    make -C /tmp/libfaketime-${FAKETIME_VERSION}/src FAKETIME_COMPILE_CFLAGS="-UFAKE_PTHREAD" libfaketime.so.1 && \
    install -D -m 644 /tmp/libfaketime-${FAKETIME_VERSION}/src/libfaketime.so.1 /usr/local/lib/faketime/libfaketime.so.1

FROM ${BASE_IMAGE}

USER root
RUN apt-get update -qq && \
    apt-get install --no-install-recommends -y procps && \
    rm -rf /var/lib/apt/lists /var/cache/apt/archives

COPY --from=faketime /usr/local/lib/faketime/libfaketime.so.1 /usr/local/lib/faketime/libfaketime.so.1
COPY --chmod=755 entrypoint /usr/local/bin/parity-entrypoint
# Instances run as the invoking uid (root inside bench/bin/runner): let any uid write.
RUN chmod -R a+rwX /rails/storage /rails/var

USER 1000:1000
ENV TZ=UTC
ENTRYPOINT ["/usr/local/bin/parity-entrypoint"]
CMD ["bin/start"]
