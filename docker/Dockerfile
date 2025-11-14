FROM php:8.4-fpm-alpine

WORKDIR /var/www

RUN apk add --no-cache \
    nginx \
    build-base \
    mysql-client \
    curl \
    libxml2-dev \
    oniguruma-dev \
    libzip-dev \
    gd-dev \
    icu-dev \
    xz

RUN docker-php-ext-install pdo_mysql opcache bcmath exif pcntl gd zip intl xml

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN apk add --no-cache nodejs npm

RUN docker-php-ext-enable pdo_mysql

RUN ls -l /usr/local/bin && which node && node -v || echo "Node.js no encontrado tras la instalación"

ENV PATH="/usr/local/bin:/usr/bin:$PATH"

RUN chown -R www-data:www-data /var/www && \
    chmod -R 775 /var/www

EXPOSE 9000
CMD ["php-fpm"]
