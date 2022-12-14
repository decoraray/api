FROM php:8.1-fpm-alpine

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql sockets

# Install Composer
RUN curl -sS https://getcomposer.org/installer​ | php -- \
    --install-dir=/usr/local/bin --filename=composer

# copy the application files
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# set working directory
WORKDIR /app

# copy the application files
COPY . .

# install dependencies
RUN composer install --no-dev --optimize-autoloader


