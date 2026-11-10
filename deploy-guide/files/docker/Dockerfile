# HotelMS – production PHP image for the VPS Docker option (deploy-guide/vps.md, option A).
# Same PHP version and extensions as the project's development image (backend/Dockerfile), plus FPM instead of
# "artisan serve". UNTESTED in this guide's workspace (no Docker daemon there) – see vps.md "What was tested".
FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libicu-dev unzip \
    && docker-php-ext-install pdo_mysql zip intl bcmath pcntl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
# mbstring, openssl, zlib, fileinfo, ctype, curl, dom, tokenizer and xml are already part of the php:8.3 image.

COPY 99-hotelms.ini /usr/local/etc/php/conf.d/99-hotelms.ini

# The app lives on the server in /var/www (mounted, see docker-compose.prod.yml), not inside the image.
WORKDIR /var/www/hotelms
# php-fpm listens on port 9000 and runs PHP as www-data (uid 33 – the same uid as www-data on Ubuntu).
