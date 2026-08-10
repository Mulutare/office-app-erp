# syntax=docker/dockerfile:1

FROM composer:2 AS php-dependencies

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --ignore-platform-req=ext-gd \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.4-apache-bookworm AS runtime-base

ENV APP_HOME=/var/www/html/office_app

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
    a2enmod rewrite headers; \
    sed -ri 's!Listen 80!Listen 8080!' \
        /etc/apache2/ports.conf; \
    sed -ri 's!<VirtualHost \\*:80>!<VirtualHost *:8080>!' \
        /etc/apache2/sites-available/000-default.conf; \
    rm -rf /var/lib/apt/lists/*

COPY docker/apache/officeapp.conf \
    /etc/apache2/conf-available/officeapp.conf

RUN set -eux; \
    a2enconf officeapp; \
    mkdir -p \
        "${APP_HOME}/storage/cache" \
        "${APP_HOME}/storage/logs" \
        "${APP_HOME}/storage/uploads" \
        /var/lock/apache2 \
        /var/run/apache2; \
    chown -R www-data:www-data \
        /var/lock/apache2 \
        /var/run/apache2 \
        /var/log/apache2

WORKDIR ${APP_HOME}

COPY --from=php-dependencies \
    /app/vendor /opt/officeapp/vendor

EXPOSE 8080

FROM runtime-base AS development

COPY docker/php/development.ini \
    /usr/local/etc/php/conf.d/officeapp.ini

COPY --chown=www-data:www-data . ${APP_HOME}

USER www-data

FROM runtime-base AS test

COPY docker/php/testing.ini \
    /usr/local/etc/php/conf.d/officeapp.ini

COPY --chown=www-data:www-data . ${APP_HOME}

USER www-data

FROM runtime-base AS production

COPY docker/php/production.ini \
    /usr/local/etc/php/conf.d/officeapp.ini

COPY --chown=root:www-data . ${APP_HOME}

RUN set -eux; \
    rm -rf \
        "${APP_HOME}/tests"; \
    rm -f \
        "${APP_HOME}/bin/bootstrap-development-admin.php"; \
    chmod -R u=rwX,g=rX,o= \
        "${APP_HOME}"; \
    chown -R www-data:www-data \
        "${APP_HOME}/storage"; \
    chmod -R u=rwX,g=,o= \
        "${APP_HOME}/storage"

USER www-data

# Optional Oracle targets. These stages are not built by the standard
# development, test or production workflows.
FROM runtime-base AS oracle-extension

USER root

COPY docker/oracle/instantclient/basiclite.zip \
    /tmp/oracle-basiclite.zip
COPY docker/oracle/instantclient/sdk.zip \
    /tmp/oracle-sdk.zip

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libaio1 \
        unzip; \
    mkdir -p /opt/oracle; \
    unzip -q /tmp/oracle-basiclite.zip \
        -d /opt/oracle; \
    unzip -q /tmp/oracle-sdk.zip \
        -d /opt/oracle; \
    instant_dir="$(find /opt/oracle \
        -maxdepth 1 -type d -name 'instantclient_*' \
        | head -n 1)"; \
    test -n "${instant_dir}"; \
    ln -s "${instant_dir}" \
        /opt/oracle/instantclient; \
    echo /opt/oracle/instantclient \
        > /etc/ld.so.conf.d/oracle-instantclient.conf; \
    ldconfig; \
    docker-php-ext-configure pdo_oci \
        --with-pdo-oci=instantclient,/opt/oracle/instantclient; \
    docker-php-ext-install -j"$(nproc)" pdo_oci; \
    rm -rf \
        /var/lib/apt/lists/* \
        /tmp/oracle-basiclite.zip \
        /tmp/oracle-sdk.zip

FROM oracle-extension AS oracle-development

COPY docker/php/development.ini \
    /usr/local/etc/php/conf.d/officeapp.ini

COPY --chown=www-data:www-data . ${APP_HOME}

USER www-data

FROM oracle-extension AS oracle-production

COPY docker/php/production.ini \
    /usr/local/etc/php/conf.d/officeapp.ini

COPY --chown=root:www-data . ${APP_HOME}

RUN set -eux; \
    rm -rf "${APP_HOME}/tests"; \
    rm -f \
        "${APP_HOME}/bin/bootstrap-development-admin.php"; \
    chmod -R u=rwX,g=rX,o= "${APP_HOME}"; \
    chown -R www-data:www-data \
        "${APP_HOME}/storage"; \
    chmod -R u=rwX,g=,o= \
        "${APP_HOME}/storage"

USER www-data
