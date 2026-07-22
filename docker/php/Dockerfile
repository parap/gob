FROM php:8.4-fpm

# The MySQL PDO driver isn't bundled in the base image; add it.
RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html
