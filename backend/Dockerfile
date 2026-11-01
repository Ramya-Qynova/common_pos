# section 12 Phase 8 "Docker/hosting". PHP 8.3 (section 5's actual
# minimum - composer.json's ^8.2 floor is D-0001's dev-machine workaround,
# not the production target) with the extensions every service in this
# codebase actually uses: pdo_mysql (every model), bcmath (every money
# calculation - PricingEngine, StockService, every ledger service), redis
# (section 2 "Redis in prod" - cache/queue/session driver).
FROM php:8.3-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip libpng-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install pdo_mysql bcmath mbstring xml zip gd opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM base AS deps
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM base AS app
COPY --from=deps /var/www/html/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
CMD ["php-fpm"]
