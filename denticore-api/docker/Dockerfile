# Imágenes OCI de la API (SDD §1.11): denticore-api (php-fpm + nginx),
# denticore-worker y denticore-scheduler. Configuración solo por variables de entorno.

FROM composer:2 AS composer

FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache icu-libs libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_pgsql zip \
    && apk del .build-deps

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

FROM base AS vendor

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm -rf tests docker .env .env.* \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

FROM base AS api

RUN apk add --no-cache nginx supervisor
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

EXPOSE 8080
CMD ["supervisord", "-c", "/etc/supervisord.conf"]

FROM base AS worker

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
USER www-data
# Colas de SDD §1.9, en orden de prioridad.
CMD ["php", "artisan", "queue:work", "redis", "--queue=critical,notifications,documents,heavy,default", "--tries=3", "--max-time=3600"]

FROM base AS scheduler

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
USER www-data
CMD ["php", "artisan", "schedule:work"]
