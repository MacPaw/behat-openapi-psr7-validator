FROM php:8.3-cli-alpine

RUN apk add --no-cache git linux-headers $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
