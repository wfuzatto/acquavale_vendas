FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite headers expires
COPY docker/php.ini /usr/local/etc/php/conf.d/acquavale.ini
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html
