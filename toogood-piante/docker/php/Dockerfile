FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev default-mysql-client \
    && docker-php-ext-install pdo_mysql bcmath intl zip pcntl \
    && rm -rf /var/lib/apt/lists/*

# Solo sviluppo: PHP-FPM gira come root (avviato con "php-fpm -R") come i comandi artisan e la coda.
# Sui dischi condivisi di Windows i permessi dei file non sono affidabili e con utenti diversi
# i file di log e di cache creati da uno non sono scrivibili dall'altro.
RUN sed -i 's/^user = www-data/user = root/; s/^group = www-data/group = root/' /usr/local/etc/php-fpm.d/www.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
