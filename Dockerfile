FROM php:8.5-apache

# System packages: Composer's own needs plus the build dependencies of the PHP
# extensions compiled below. Nothing else: every entry has a consumer.
RUN apt-get update && apt-get install -y \
    # git: Composer VCS repositories and --prefer-source installs
    git \
    # curl: HTTP checks against the running app from inside the container
    curl \
    # unzip: Composer extracts dist archives with it
    unzip \
    # libpng-dev: PNG support for the gd build
    libpng-dev \
    # libjpeg62-turbo-dev: JPEG support for the gd build (--with-jpeg)
    libjpeg62-turbo-dev \
    # libfreetype6-dev: FreeType support for the gd build (--with-freetype)
    libfreetype6-dev \
    # libzip-dev: backend library of the zip extension
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions on top of the base image, which already bundles pdo, pdo_sqlite,
# sqlite3, mbstring and the XML family (xml, dom, xmlwriter, simplexml).
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    # pdo_mysql: MySQL driver for the compose database service
    pdo_mysql \
    # gd: image processing capability for media handling in extensions and themes
    gd \
    # zip: package archive handling (installer, self-updater, filesystem archives)
    zip

# Install Composer 2.0+
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Configure Apache. The webroot is public/; everything else in the project stays
# outside the document root. FollowSymLinks is required for the public/storage
# symlink that exposes the media library.
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html/public\n\
    <Directory /var/www/html/public>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
    </VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# No application code is baked in: the dev stack bind-mounts the project over
# /var/www/html and Composer dependencies are installed in the running container.

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]
