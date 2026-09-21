FROM php:8.5-cli-alpine

RUN apk add --no-cache \
        git \
        imagemagick \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        freetype-dev \
        unzip \
    && apk add --no-cache --virtual .imagick-build-dependencies \
        $PHPIZE_DEPS \
        imagemagick-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && apk del .imagick-build-dependencies

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

EXPOSE 8000
