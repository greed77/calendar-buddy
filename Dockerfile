# --- Front-end assets (Tailwind / Vite) ---
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY . .
RUN npm run build

# --- PHP application (nginx + php-fpm, listens on 8080) ---
FROM serversideup/php:8.4-fpm-nginx AS app

ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true

COPY --chown=www-data:www-data . /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

RUN composer install --no-dev --optimize-autoloader --no-interaction
