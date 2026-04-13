FROM php:8.3-fpm

# Install system dependencies (REQUIRED for pecl)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    git \
    curl \
    autoconf \
    gcc \
    make \
    pkg-config

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql zip

# ✅ Install Redis extension (PROPER WAY)
RUN pecl install redis \
    && docker-php-ext-enable redis

# Verify (optional debug)
RUN php -m | grep redis || true

WORKDIR /var/www

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer