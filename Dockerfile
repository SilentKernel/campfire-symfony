# syntax = docker/dockerfile:1
#
# Production image: a drop-in for the Rails one (reference/Dockerfile). Same working directory
# (/rails), storage layout (/rails/storage/{db,files,backups}), default user (1000:1000), ports,
# environment and ONCE hooks (/hooks). Runs under any --user uid:gid.
#
#   docker build -t campfire-symfony --build-arg APP_VERSION=... --build-arg GIT_REVISION=... .
#   docker build --target dev -t campfire-symfony:dev .     # bin/check's toolchain
#
# Stages: base (FrankenPHP, PHP extensions, libvips, ffmpeg) -> deps (Composer, prod vendor/)
# -> build (app, compiled assets, warmed cache) -> prod (default); dev = deps + dev vendor/ + tests.

# FrankenPHP 1.13 / PHP 8.4 ZTS / Caddy 2.11 on Debian trixie (linux/amd64, linux/arm64, ...).
ARG FRANKENPHP_IMAGE=docker.io/dunglas/frankenphp:1-php8.4-trixie@sha256:81f7030a2b7230f26dbdf281bee328e03221a33b3545a5d432d7f67e3346d704
ARG COMPOSER_IMAGE=docker.io/library/composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac

FROM ${COMPOSER_IMAGE} AS composer


# SQLite itself, built from the official amalgamation. Debian trixie's libsqlite3-0 (3.46.1, which
# pdo_sqlite links against) has the WAL-reset bug (https://sqlite.org/wal.html#walresetbug,
# 3.7.0 through 3.51.2): two connections writing or checkpointing at the same instant can lose
# part of a transaction during a checkpoint, and SQLite then reports "database disk image is
# malformed". FrankenPHP's worker threads, campfire:cable and messenger:consume are exactly
# that pattern (docs/internal/sqlite-corruption.md). Rails' sqlite3 gem 2.9.6 bundles 3.53.2.
# The library replaces the system one for every process in the image (pdo_sqlite, sqlite3, the
# sqlite3 CLI of the ONCE hooks): it goes first on the loader path, with the same soname.
FROM ${FRANKENPHP_IMAGE} AS sqlite
ARG SQLITE_VERSION=3530400
ARG SQLITE_YEAR=2026
ARG SQLITE_SHA3_256=454e45f61c6bd75b7420e7190732dea03ce6639c63ada47bbc592f67fc340338
# Debian's compile options (`PRAGMA compile_options` of libsqlite3-0), so nothing else changes.
RUN set -eux; \
    cd /tmp; \
    curl -fsSLo sqlite.tar.gz "https://www.sqlite.org/${SQLITE_YEAR}/sqlite-autoconf-${SQLITE_VERSION}.tar.gz"; \
    echo "SHA3-256(sqlite.tar.gz)= ${SQLITE_SHA3_256}" > sqlite.sha3; \
    [ "$(openssl dgst -sha3-256 sqlite.tar.gz)" = "$(cat sqlite.sha3)" ]; \
    tar xzf sqlite.tar.gz; \
    cd "sqlite-autoconf-${SQLITE_VERSION}"; \
    CFLAGS="-O2 -g0 -DSQLITE_ENABLE_COLUMN_METADATA -DSQLITE_ENABLE_FTS3_PARENTHESIS \
      -DSQLITE_ENABLE_FTS3_TOKENIZER -DSQLITE_ENABLE_PREUPDATE_HOOK -DSQLITE_ENABLE_STMTVTAB \
      -DSQLITE_ENABLE_UNLOCK_NOTIFY -DSQLITE_LIKE_DOESNT_MATCH_BLOBS -DSQLITE_MAX_SCHEMA_RETRY=25 \
      -DSQLITE_MAX_VARIABLE_NUMBER=250000 -DSQLITE_SECURE_DELETE -DSQLITE_SOUNDEX -DSQLITE_USE_URI \
      -DSQLITE_DEFAULT_SECTOR_SIZE=4096" \
      ./configure --prefix=/usr/local --libdir="/usr/local/lib/$(gcc -dumpmachine)" --soname=legacy \
        --disable-static --disable-readline --fts3 --fts4 --fts5 --rtree --session --dbpage --dbstat; \
    make -j"$(nproc)"; \
    make install DESTDIR=/sqlite; \
    rm -rf /sqlite/usr/local/share /sqlite/usr/local/lib/*/pkgconfig


FROM ${FRANKENPHP_IMAGE} AS base

# libvips42t64: jcupitt/vips binds it through FFI; libvips-tools (vips CLI) as a fallback.
# ffmpeg/ffprobe: video previews and analysis (Active Storage).
RUN apt-get update -qq && \
    apt-get install --no-install-recommends -y libvips42t64 libvips-tools ffmpeg && \
    rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/*

# SQLite (see the sqlite stage) and its CLI, for the ONCE hooks. /usr/local/lib/<multiarch> comes
# first in /etc/ld.so.conf.d/<multiarch>.conf, ahead of Debian's copy; the check fails the build
# unless PHP really loads it. sqlite3.h stays for tests/Container/sqlite.sh's race reproducer.
COPY --from=sqlite /sqlite/usr/local/ /usr/local/
RUN ldconfig && \
    php -r 'exit(version_compare((new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(), "3.51.3", ">=") && version_compare(SQLite3::version()["versionString"], "3.51.3", ">=") ? 0 : 1);' && \
    sqlite3 --version

# apcu: cache.app (shared by worker threads); event: Workerman's event loop (campfire:cable);
# ffi: libvips; gmp + bcmath: Web Push (minishlink/web-push); intl, pcntl, posix, sockets: Symfony,
# Messenger and Workerman. pdo_sqlite, opcache, sodium and posix ship with the base image.
RUN install-php-extensions apcu bcmath event ffi gmp intl pcntl sockets

# The base image gives frankenphp cap_net_bind_service as a file capability; under
# --cap-drop=ALL that makes exec fail. Without it, root still binds :80, and other users bind
# high ports (Docker's own network namespaces also allow low ports to everyone).
RUN cp /usr/local/bin/frankenphp /tmp/frankenphp && mv /tmp/frankenphp /usr/local/bin/frankenphp && \
    ! getcap /usr/local/bin/frankenphp | grep -q cap_

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-campfire.ini

WORKDIR /rails

ENV XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1


# Production dependencies, cached on composer.lock alone.
FROM base AS deps
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN apt-get update -qq && \
    apt-get install --no-install-recommends -y unzip git && \
    rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/*
COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,id=campfire-symfony-composer,target=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist


# The toolchain bin/check runs: dev dependencies, the tests, PHP's development error settings.
FROM deps AS dev
RUN --mount=type=cache,id=campfire-symfony-composer,target=/tmp/composer-cache \
    composer install --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist
COPY . .
RUN composer dump-autoload --no-interaction && \
    mkdir -p var && chmod -R a+rwX var && \
    printf '%s\n' 'memory_limit = -1' 'zend.assertions = 1' 'display_errors = stderr' \
      'error_reporting = E_ALL' > $PHP_INI_DIR/conf.d/zzz-dev.ini


# The application, with its autoloader, environment, compiled assets and warmed container.
FROM deps AS build
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction && \
    APP_ENV=prod composer dump-env prod --no-interaction && \
    rm -rf tests var/* storage .env.dev .env.test phpunit.dist.xml .php-cs-fixer.dist.php \
      phpstan.dist.neon Dockerfile
# A throwaway SECRET_KEY_BASE: nothing signed at build time survives into the image.
RUN APP_ENV=prod APP_DEBUG=0 SECRET_KEY_BASE=build-only-dummy-secret \
      php bin/console asset-map:compile --no-interaction && \
    APP_ENV=prod APP_DEBUG=0 SECRET_KEY_BASE=build-only-dummy-secret \
      php bin/console cache:warmup --no-interaction && \
    rm -rf var/log/* && \
    mkdir -p var/run var/log storage/db storage/files storage/backups && \
    chmod -R a+rwX var storage


FROM base AS prod

# Image metadata
ARG OCI_DESCRIPTION
LABEL org.opencontainers.image.description="${OCI_DESCRIPTION}"
ARG OCI_SOURCE
LABEL org.opencontainers.image.source="${OCI_SOURCE}"
LABEL org.opencontainers.image.licenses="MIT"

RUN groupadd --system --gid 1000 rails && \
    useradd rails --uid 1000 --gid 1000 --create-home --shell /bin/bash

# The code stays root-owned and read-only; var/ (cache pools, cable socket) and storage/ are
# writable by whatever uid the container runs as.
COPY --from=build /rails /rails

# Install ONCE backup/restore hooks
COPY --chmod=755 hooks /hooks

USER 1000:1000

ENV APP_ENV=prod \
    CAMPFIRE_STORAGE_PATH=/rails/storage \
    HTTP_IDLE_TIMEOUT=60 \
    HTTP_READ_TIMEOUT=300 \
    HTTP_WRITE_TIMEOUT=300

# Set version and revision
ARG APP_VERSION
ENV APP_VERSION=$APP_VERSION
ARG GIT_REVISION
ENV GIT_REVISION=$GIT_REVISION

# Expose ports for HTTP and HTTPS
EXPOSE 80 443

# The base image's healthcheck polls Caddy's admin API, which is off; the Rails image has none.
HEALTHCHECK NONE

# Start the server by default, this can be overwritten at runtime
CMD ["bin/start"]
