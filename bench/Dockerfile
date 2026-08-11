# illumi-search benchmark image (matches BENCHMARK_CAPACITY.md)
#
# Build:
#   podman build -t illumi-bench-php:8.5 -f bench/Dockerfile .
#
# This image is the reproducible reference environment for the capacity
# report. It ships the runtime extensions the engines need (mbstring for all
# engines, intl for Unicode/CJK processing, pcntl for FileEngine concurrent
# chunk processing, pdo_sqlite for the default FTS5 engine, pdo_mysql and
# pdo_pgsql for the SQL engines).
#
# NOTE: mysql/pgsql/meilisearch are NOT bundled here. The benchmark connects
# to host services via `--network=host` (see bench/run.sh).

FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        libsqlite3-dev \
        libpq-dev \
        default-mysql-client \
        git \
        unzip \
        sqlite3 \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        mbstring \
        pcntl \
        pdo_sqlite \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Composer (official installer, checksum-verified from the signed distribution)
RUN php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');" \
    && php -r "if (hash_file('sha384', '/tmp/composer-setup.php') !== file_get_contents('https://composer.github.io/installer.sig')) { unlink('/tmp/composer-setup.php'); echo 'ERROR: composer installer checksum mismatch' . PHP_EOL; exit(1); }" \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php

WORKDIR /app

CMD ["php", "-v"]
