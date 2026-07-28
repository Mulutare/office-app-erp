# syntax=docker/dockerfile:1

FROM php:8.4-apache-bookworm AS runtime-base

ENV APP_HOME=/var/www/html/office_app

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libonig-dev; \
    docker-php-ext-install -j"$(nproc)" \
        mbstring \
        opcache \
        pdo_mysql; \
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
