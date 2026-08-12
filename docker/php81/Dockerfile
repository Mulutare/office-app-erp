# syntax=docker/dockerfile:1

FROM php:8.1-cli-bookworm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        gd \
        mbstring \
        opcache \
        pdo_mysql \
        zip; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html/office_app

COPY . .

COPY --from=composer:2 \
    /usr/bin/composer /usr/local/bin/composer

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

CMD ["php", "tests/run.php"]
