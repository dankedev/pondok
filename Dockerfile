FROM node:26-bookworm-slim AS assets

WORKDIR /app

RUN npm install --global pnpm@12

COPY . .

RUN pnpm install --frozen-lockfile \
    && pnpm run build \
    && test -f public/build/manifest.json

FROM php:8.4-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=mysql \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu72 \
        libicu-dev \
        libonig5 \
        libonig-dev \
        libpq5 \
        libpq-dev \
        libzip4 \
        libzip-dev \
        unzip \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" intl mbstring pdo_mysql pdo_pgsql zip \
    && apt-mark manual libicu72 libonig5 libpq5 libzip4 \
    && apt-get purge -y --auto-remove \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* \
    && printf 'upload_max_filesize=10M\npost_max_size=12M\nmemory_limit=256M\n' \
        > /usr/local/etc/php/conf.d/pondok.ini \
    && a2enmod rewrite

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    && mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && ln -s ../storage/app/public public/storage

EXPOSE 80

CMD ["apache2-foreground"]
