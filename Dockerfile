# ==========================================
# Stage 1: Build Frontend Assets (Vite)
# ==========================================
FROM node:20-alpine AS node_builder

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .
RUN npm run build

# ==========================================
# Stage 2: Production PHP-FPM & Nginx
# ==========================================
FROM php:8.3-fpm-alpine

# Install system utilities and runtime libraries
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    bash \
    git \
    unzip \
    libpng \
    libjpeg-turbo \
    freetype \
    libzip \
    icu-libs

# Install PHP extensions using official mlocati installer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
    pdo_mysql \
    bcmath \
    gd \
    zip \
    intl \
    pcntl \
    opcache \
    redis

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Copy composer definition files first to leverage Docker layer caching
COPY composer.json composer.lock ./

# Install production PHP dependencies without dev packages or running scripts
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy full application codebase
COPY . .

# Copy compiled frontend assets from Stage 1
COPY --from=node_builder /app/public/build ./public/build

# Complete composer autoload generation
RUN composer dump-autoload --optimize --no-dev

# Copy server and process configurations
COPY deploy/nginx.conf /etc/nginx/nginx.conf
COPY deploy/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint.sh

# Set executable permissions and storage ownership
RUN chmod +x /usr/local/bin/entrypoint.sh && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Expose default HTTP port
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
