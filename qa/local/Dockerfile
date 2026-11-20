# Local synthetic QA ONLY; image is never production deployable.
FROM php:8.2-apache-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 pdo_mysql mysqli mbstring intl zip gd \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY .htaccess app.html index.php settings.php setup.php version.php /var/www/html/
COPY api/ /var/www/html/api/
COPY assets/ /var/www/html/assets/
COPY private/app/ /var/www/html/private/app/
COPY private/tests/ /var/www/html/private/tests/
COPY qa/local/apache-ipdams-qa.conf /etc/apache2/conf-available/ipdams-local-qa.conf
COPY qa/local/check_schema.php /opt/ipdams-qa/check_schema.php
RUN a2enconf ipdams-local-qa \
    && mkdir -p /var/www/html/private/storage/private /var/www/html/private/uploads \
    && chown -R www-data:www-data /var/www/html/private/storage /var/www/html/private/uploads
# No .env, uploads, DB dumps, backups, Git metadata, or Hostinger configuration is copied.
EXPOSE 80
