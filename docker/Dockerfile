# Development / test image for json-mine.
# All quality gates (composer, phpunit, phpstan, php-cs-fixer) run inside this image — see Makefile.
FROM php:8.4-cli-alpine

# pcov for fast code coverage in tests
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_CACHE_DIR=/app/.cache/composer
WORKDIR /app
