FROM php:8.3-cli

# gd (with JPEG/PNG/WebP/FreeType) is needed by GallerySeeder to draw placeholder images.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev libicu-dev \
        libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_mysql mbstring zip intl bcmath gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
