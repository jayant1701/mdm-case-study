FROM php:8.3-cli
RUN apt-get update && apt-get install -y git unzip libzip-dev libonig-dev librdkafka-dev && docker-php-ext-install pdo_mysql zip mbstring pcntl && pecl install rdkafka-6.0.5 && docker-php-ext-enable rdkafka && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && chmod -R 775 storage bootstrap/cache
EXPOSE 8000
CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000 --no-reload"]



