FROM php:8.5-fpm-bookworm

RUN apt-get update \
    && apt-get install --yes --no-install-recommends git libonig-dev libsqlite3-dev libxml2-dev libzip-dev unzip \
    && docker-php-ext-install dom mbstring opcache pdo_mysql pdo_sqlite xml xmlreader xmlwriter zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
