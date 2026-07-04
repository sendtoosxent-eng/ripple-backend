FROM php:8.3-cli

# System dependencies + PHP extensions Laravel needs
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libsqlite3-dev sqlite3 \
    && docker-php-ext-install pdo pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

# Make sure the SQLite database file + storage dirs exist and are writable
RUN mkdir -p database storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && touch database/database.sqlite \
    && chmod -R 775 database storage bootstrap/cache

EXPOSE 10000

CMD php artisan migrate --force && php artisan storage:link && php artisan serve --host 0.0.0.0 --port ${PORT:-10000}
