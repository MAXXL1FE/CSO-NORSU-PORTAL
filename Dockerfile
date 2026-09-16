FROM php:8.2-apache

# Extensions this app needs: mysqli (database), mbstring (functions.php),
# gd + fileinfo (image upload validation in includes/functions.php)
RUN apt-get update && apt-get install -y libpng-dev libzip-dev \
    && docker-php-ext-install mysqli mbstring gd \
    && docker-php-ext-enable mysqli mbstring gd

# Apache: serve the app from the repo root, enable .htaccess (used by
# assets/uploads/.htaccess to block execution of uploaded files)
RUN a2enmod rewrite
COPY . /var/www/html/
RUN sed -ri -e 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Writable uploads folder
RUN chown -R www-data:www-data /var/www/html/assets/uploads

# Railway/Render inject $PORT; Apache needs to listen on it
RUN sed -ri -e 's/Listen 80/Listen ${PORT:-80}/g' /etc/apache2/ports.conf \
    && sed -ri -e 's/:80>/:${PORT:-80}>/g' /etc/apache2/sites-available/000-default.conf
ENV PORT=80
EXPOSE 80

CMD ["sh", "-c", "apache2-foreground"]
