# Pinned to the bookworm variant and an exact PHP release for reproducibility.
# 8.2.35 is the minimum safe 8.2 release: anything older is vulnerable to the
# unauthenticated RCEs CVE-2026-109618 / CVE-2026-109619 ("PHP2Shell", fixed
# 2026-10-21). Bump this for future PHP security releases; never go below it.
# For full immutability, pin by digest. Get it with:
#   docker buildx imagetools inspect php:8.2.35-apache-bookworm --format '{{.Manifest.Digest}}'
# then use:  FROM php:8.2.35-apache-bookworm@sha256:<digest>
FROM php:8.2.35-apache-bookworm

# Enable mod_rewrite, plus mod_remoteip so the access log and PHP see the real
# client IP rather than the reverse proxy's (see apache.conf)
RUN a2enmod rewrite remoteip
COPY apache.conf /etc/apache2/conf-enabled/synccit.conf

# Install mysqli extension
RUN docker-php-ext-install mysqli

# Disable session upload progress (unused by synccit). It is an RCE trigger for
# CVE-2026-109619 and the not-yet-fixed CVE-2026-115560, and php.net advises
# turning it off even on patched releases. Set in php.ini (not .htaccess /
# php_admin_flag) so it applies at startup, before any request is parsed.
RUN echo 'session.upload_progress.enabled = 0' > "$PHP_INI_DIR/conf.d/zz-synccit.ini"

# Install Composer (build-time only — not present in the final runtime layer's PATH use)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Install MySQL client (used in entrypoint for health check and schema init) + unzip for Composer
RUN apt-get update && apt-get install -y default-mysql-client unzip && rm -rf /var/lib/apt/lists/*

# Install PHP dependencies (PHPMailer) — copy composer.json first for layer caching
COPY composer.json /var/www/html/composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --working-dir=/var/www/html

# Copy application source (vendor/ is in .dockerignore so it won't overwrite the installed deps)
COPY . /var/www/html/

COPY docker-entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# ── Run as the unprivileged www-data user ──────────────────────────────────
# Apache binds 80 only as root, so move it to the unprivileged port 8080 and
# hand the runtime directories to www-data. The container exposes 8080; map it
# to whatever host port you like in docker-compose.
RUN sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
 && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's#\$\{APACHE_LOG_DIR\}/error\.log#/dev/stderr#'  /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's#\$\{APACHE_LOG_DIR\}/access\.log#/dev/stdout#' /etc/apache2/sites-available/000-default.conf \
 && mkdir -p /var/run/apache2 /var/lock/apache2 /var/lib/php/sessions \
 && chown -R www-data:www-data \
        /var/www/html \
        /var/run/apache2 \
        /var/lock/apache2 \
        /var/log/apache2 \
        /var/lib/php/sessions

EXPOSE 8080
USER www-data
ENTRYPOINT ["/entrypoint.sh"]
