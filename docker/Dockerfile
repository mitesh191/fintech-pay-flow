FROM php:8.3-fpm-alpine

# Install dependencies (ALPINE WAY)
RUN apk add --no-cache \
    git \
    curl \
    unzip \
    libzip-dev \
    zip

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql zip bcmath

# Install Redis extension required by composer platform checks
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer
RUN curl -sS https://getcomposer.org/installer -o composer-setup.php \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php

# Verify
RUN composer --version

# Apply runtime configuration.
# These files live in docker/php/ and are copied here so they are active
# at the correct PHP-FPM config paths — simply mounting the project volume
# does NOT make PHP read them (PHP looks in /usr/local/etc/php, not /var/www).
COPY docker/php/php.ini      /usr/local/etc/php/php.ini
COPY docker/php/opcache.ini  /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/zz-www.conf  /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /var/www

CMD ["php-fpm"]