FROM php:8.2-cli
ENV DEBIAN_FRONTEND=noninteractive
WORKDIR /var/www/html

# PHP extensions + sistema base
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 \
    python3-pip \
    curl \
    wget \
    ca-certificates \
    bzip2 \
    unzip \
    libglib2.0-0 \
    libzip-dev \
    libonig-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install gd pdo_mysql zip \
  && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Solo dependencias PHP — sin scripts, sin autoloader
# (artisan y bootstrap/ no existen aún, no podemos correr package:discover)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-dev --prefer-dist \
    --no-scripts --no-autoloader

# Ahora sí copiamos todo el código
COPY . .

# Con bootstrap/ disponible, generamos autoloader y corremos los scripts de Laravel
RUN composer dump-autoload --optimize \
 && php artisan package:discover --ansi

# Python — --break-system-packages es necesario en Debian con Python 3.13+
RUN pip3 install --no-cache-dir --break-system-packages \
    -r app/Services/face-recognition/requirements.txt

# Permisos Laravel
RUN mkdir -p \
    storage/app/private/faces \
    storage/bootstrap/cache \
    bootstrap/cache \
  && chown -R www-data:www-data storage bootstrap/cache

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/face-entrypoint
RUN chmod +x /usr/local/bin/face-entrypoint

EXPOSE 8000
ENTRYPOINT ["face-entrypoint"]