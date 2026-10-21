# Pinned to 8.3.36+ for CVE-2026-109618 / CVE-2026-109619 (PHP2Shell)
FROM php:8.3.36-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libmagickwand-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Build imagick from GitHub source (PECL downloads are unreliable)
ARG IMAGICK_VERSION=3.8.1
RUN docker-php-source extract \
    && mkdir -p /usr/src/php/ext/imagick \
    && curl -fsSL https://github.com/Imagick/imagick/archive/refs/tags/${IMAGICK_VERSION}.tar.gz \
       | tar xz -C /usr/src/php/ext/imagick --strip-components=1 \
    && docker-php-ext-install imagick \
    && docker-php-source delete

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Get Composer
COPY --from=composer:2.5.1 /usr/bin/composer /usr/bin/composer

# PHP upload/memory config
# session.upload_progress disabled: PHP2Shell attack vector (Laravel doesn't use it)
RUN printf "file_uploads = On\nmemory_limit = 500M\nupload_max_filesize = 50M\npost_max_size = 50M\nmax_execution_time = 600\nsession.upload_progress.enabled = 0\n" \
    > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /app

# Install PHP dependencies first (layer cache friendly)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy application source
COPY . .

# Set permissions for storage and cache
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

EXPOSE 8000

# Run as unprivileged user
USER www-data

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
