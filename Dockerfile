FROM php:8.2-apache

# Enable mod_rewrite and apply AllowOverride config
RUN a2enmod rewrite
COPY apache.conf /etc/apache2/conf-enabled/synccit.conf

# Install mysqli extension
RUN docker-php-ext-install mysqli

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Install MySQL client (used in entrypoint for health check and schema init)
RUN apt-get update && apt-get install -y default-mysql-client && rm -rf /var/lib/apt/lists/*

# Install PHP dependencies (PHPMailer) — copy composer.json first for layer caching
COPY composer.json /var/www/html/composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --working-dir=/var/www/html

# Copy application source (vendor/ is in .dockerignore so it won't overwrite the installed deps)
COPY . /var/www/html/

COPY docker-entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
