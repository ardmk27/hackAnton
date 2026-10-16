FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql zip mbstring curl \
    && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
RUN printf "upload_max_filesize=10M\npost_max_size=12M\n" > /usr/local/etc/php/conf.d/uploads.ini
WORKDIR /var/www/html
COPY . /var/www/html
RUN mkdir -p storage/uploads && chown -R www-data:www-data storage
EXPOSE 80
