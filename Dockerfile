FROM php:8.2-apache

RUN docker-php-ext-install mysqli \
    && a2enmod rewrite headers

WORKDIR /var/www/html

COPY --chown=www-data:www-data index.php redirect.php config.php .htaccess ./
COPY --chown=www-data:www-data assets/ ./assets/

EXPOSE 80
