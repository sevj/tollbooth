FROM php:8.4-cli-alpine

RUN apk add --no-cache git unzip icu-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install intl opcache \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-tollbooth.ini

RUN mkdir -p /cache/tmp /cache/opcache && chmod -R 777 /cache

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /app
