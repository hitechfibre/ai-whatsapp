FROM php:8.2-apache

LABEL maintainer="HitechFibre DevOps"
LABEL description="HitechFibre AI WhatsApp Support"

# ─── System dependencies ──────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
    git curl zip unzip cron \
    libpq-dev libicu-dev libzip-dev libxml2-dev libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# ─── PHP extensions ───────────────────────────────────────────────────
RUN docker-php-ext-install \
    pdo pdo_mysql \
    mysqli \
    intl \
    zip \
    xml \
    opcache

# ─── Redis PECL extension ────────────────────────────────────────────
RUN pecl install redis && docker-php-ext-enable redis

# ─── Composer 2 ──────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ─── PHP config ───────────────────────────────────────────────────────
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# ─── Apache config ────────────────────────────────────────────────────
RUN a2enmod rewrite headers
COPY docker/php/vhost.conf /etc/apache2/sites-enabled/000-default.conf

# ─── Copy application ─────────────────────────────────────────────────
WORKDIR /var/www/html
COPY . .

# ─── Install PHP dependencies ─────────────────────────────────────────
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ─── Runtime directories ──────────────────────────────────────────────
RUN mkdir -p logs state/cache state/sessions \
    && chown -R www-data:www-data logs state config \
    && chmod -R 755 logs state \
    && chmod 664 config/settings.json 2>/dev/null || true

# ─── Entrypoint ───────────────────────────────────────────────────────
COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && chmod +x /var/www/html/artisan

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
