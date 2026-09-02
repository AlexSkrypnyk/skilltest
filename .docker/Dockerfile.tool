# Tool image: the skilltest PHAR on a PHP runtime.
#
# Runs the deterministic suite and every offline command with no PHP on the
# host:
#
#   docker run --rm -v "$PWD":/work -w /work ghcr.io/alexskrypnyk/skilltest:latest
#
# Multi-stage and self-contained: the PHAR is compiled inside the builder, so
# the image builds from a clean checkout without a published release. Pass the
# release tag as VERSION so the compiled-in `skilltest version` matches the tag.

# syntax=docker/dockerfile:1

# The apt packages track the base image's current releases, and the Composer
# steps stay in separate layers so a source change does not re-resolve
# dependencies.
# hadolint global ignore=DL3008,DL3059

FROM php:8.5-cli@sha256:9ebdf4c28ab12c02085e171c31e22ac5f7bbb6a9f6927e3bc3dfe7ee23df51e0 AS builder

COPY --from=composer:2@sha256:8fa35f42911ff8bbee92aa37d781de6799168d4a0535ac6991f1b250bc2e0245 /usr/bin/composer /usr/bin/composer

# git and unzip cover Composer's VCS and dist extraction; the base image
# already ships every extension Box needs (phar, iconv, mbstring, zlib).
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Box rewrites the PHAR when compiling, which the CLI forbids by default.
RUN echo 'phar.readonly=0' > /usr/local/etc/php/conf.d/phar.ini

WORKDIR /app
COPY . /app

ARG VERSION=development
RUN sed -i "s/\"skilltest-version\": \"development\"/\"skilltest-version\": \"${VERSION}\"/g" box.json

RUN composer install --no-interaction --no-progress
RUN composer build

FROM php:8.5-cli-alpine@sha256:763e2dc50d4b0cf8d02a1d8fbeedd43f9be879c0be928b1d6f247d45c81fa28f AS runtime

LABEL org.opencontainers.image.source="https://github.com/alexskrypnyk/skilltest"
LABEL org.opencontainers.image.description="skilltest: deterministic test runner for AI agent skills"

COPY --from=builder /app/.build/skilltest.phar /usr/local/bin/skilltest
RUN chmod +x /usr/local/bin/skilltest

WORKDIR /work
ENTRYPOINT ["skilltest"]
