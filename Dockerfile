FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM php:8.3-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite \
    && printf '%s\n' \
        'Alias /jj_kitchenette /var/www/html' \
        '<Directory /var/www/html>' \
        '    Options FollowSymLinks' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/jj-kitchenette.conf \
    && a2enconf jj-kitchenette

WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN chown -R www-data:www-data /var/www/html/uploads /var/www/html/store/uploads

EXPOSE 10000

CMD sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf \
    && sed -i "s/:80>/:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf \
    && apache2-foreground
