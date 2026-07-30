# syntax=docker/dockerfile:1

FROM php:8.1-cli-bookworm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libonig-dev; \
    docker-php-ext-install -j"$(nproc)" \
        mbstring \
        opcache \
        pdo_mysql; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html/office_app

COPY . .

CMD ["php", "tests/run.php"]

