# ADR-020 Phase 1 — single image reused by the web (php-fpm), both queue
# workers, and the scheduled Artisan commands (routes/console.php); only the
# container `command:` differs per service (see docker-compose.prod.yml).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev

# resources/views/welcome.blade.php (Laravel's default scaffold page,
# unrelated to the real API product) uses @vite() — without a built
# manifest this 500s in production. Cheap to build, matches
# composer.json's own "setup" script which already does this locally.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources/css resources/css
COPY resources/js resources/js
RUN npm run build

# composer.json declares "php": "^8.3", but composer.lock (generated
# against this project's actual local PHP 8.4.23) locked at least one
# dependency to a floor of >=8.4.1 — confirmed by a real local build
# failure on 8.3-fpm-alpine (Composer's own platform_check.php refusing to
# boot). 8.4 here matches what's actually required, not composer.json's
# looser declared constraint.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring exif pcntl bcmath gd zip intl

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
