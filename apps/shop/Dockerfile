FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    $PHPIZE_DEPS \
    git \
    curl \
    unzip \
    imagemagick \
    libmagickwand-dev \
    libheif-dev \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        mbstring \
        intl \
        xml \
        zip \
        opcache \
    && printf "\n" | pecl install imagick-3.8.1 \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*

RUN printf '%s\n' \
    'upload_max_filesize=32M' \
    'post_max_size=32M' \
    'max_file_uploads=20' \
    > /usr/local/etc/php/conf.d/99-uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

CMD ["php-fpm"]
