# Multi-target image. `dev` is the container the Compose development stack runs;
# the production runtime is the last stage, so a plain build produces it:
#
#   docker build --target dev .    development: Apache + Composer, no code baked
#   docker build .                 production: sources, dependencies and assets
#
# The two stages in between are builders. Composer and the Node toolchain run
# there and are thrown away, so neither reaches the production runtime.

# --- base: the Apache/PHP runtime both targets are built on ------------------

FROM php:8.5-apache AS base

# System packages: Composer's own needs plus the build dependencies of the PHP
# extensions compiled below. Nothing else: every entry has a consumer.
# Versions are deliberately unpinned - Debian moves point releases out of the
# archive, so a pin turns into a build that cannot be reproduced at all.
# hadolint ignore=DL3008
RUN apt-get update && apt-get install -y --no-install-recommends \
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
    && rm -rf /var/lib/apt/lists/* \
    # PHP extensions on top of the base image, which already bundles pdo,
    # pdo_sqlite, sqlite3, mbstring and the XML family (xml, dom, xmlwriter,
    # simplexml).
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
    # pdo_mysql: MySQL driver for the compose database service
    pdo_mysql \
    # gd: image processing capability for media handling in extensions and themes
    gd \
    # zip: package archive handling (installer, self-updater, filesystem archives)
    zip \
    # rewrite: the front controller routing in public/.htaccess.
    # headers and expires: the security headers and cache lifetimes of the same
    # file, which Apache silently skips while the modules are absent.
    && a2enmod rewrite headers expires

WORKDIR /var/www/html

# --- dev: the image the development stack mounts the working tree into -------

FROM base AS dev

# Install Composer 2.0+
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure Apache. The webroot is public/; everything else in the project stays
# outside the document root. FollowSymLinks is required for the public/storage
# symlink that exposes the media library.
#
# The log paths reach the file verbatim, ${APACHE_LOG_DIR} included: Apache
# expands it from its own envvars, so the shell writing the file must not.
# hadolint ignore=SC2016
RUN printf '%s\n' \
    '<VirtualHost *:80>' \
    '    DocumentRoot /var/www/html/public' \
    '    <Directory /var/www/html/public>' \
    '        Options -Indexes +FollowSymLinks' \
    '        AllowOverride All' \
    '        Require all granted' \
    '    </Directory>' \
    '    ErrorLog ${APACHE_LOG_DIR}/error.log' \
    '    CustomLog ${APACHE_LOG_DIR}/access.log combined' \
    '</VirtualHost>' \
    > /etc/apache2/sites-available/000-default.conf

# No application code is baked in: the dev stack bind-mounts the project over
# /var/www/html and Composer dependencies are installed in the running container.

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]

# --- composer-deps: the PHP dependencies of a production install -------------

FROM base AS composer-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# Manifests first: the dependency layer is then rebuilt only when they change,
# not on every source edit.
COPY composer.json composer.lock ./

RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

# The class map is generated from the sources, so they have to be in place. It
# stays a plain optimization and is never authoritative: extensions and themes
# register their namespaces with the class loader while the application boots,
# and an authoritative map answers for those classes before PSR-4 is consulted.
COPY app ./app

RUN composer dump-autoload --no-dev --optimize

# --- assets: the webroot the frontend build produces -------------------------

FROM node:22-bookworm-slim AS assets

# Debian rather than Alpine: the bundles are built by prebuilt platform binaries
# (esbuild) that expect glibc, the same C library the runtime stage has.
WORKDIR /build

# corepack fetches the pnpm version pinned in package.json#packageManager, and
# asks before downloading unless it is told that no one is watching.
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0

RUN corepack enable

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./

RUN pnpm install --frozen-lockfile

COPY scripts ./scripts
COPY app ./app
COPY packages ./packages

# Writes the JS bundles, the compiled stylesheets and the copied assets into
# public/, which is the only thing the runtime stage takes from here.
RUN pnpm build

# --- prod: the production runtime -------------------------------------------

FROM base AS prod

# Where the writable state that has to outlive the container lives: config.php
# and, for an SQLite installation, the database file.
ENV PAGEKIT_DATA_DIR=/var/www/data

# php.ini-production is the shipped baseline; docker/php/php-prod.ini below
# overrides what Pagekit needs on top of it. OPcache is built into the base
# image but left disabled there.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && docker-php-ext-enable opcache

COPY docker/php/php-prod.ini "$PHP_INI_DIR/conf.d/zz-pagekit-prod.ini"

# An unprivileged port, because the server runs as www-data and cannot bind 80.
# AllowOverride keeps the committed public/.htaccess in charge of rewrites and
# headers, so the container serves the same rule set as a shared host. Logs go
# to the container's own streams instead of files nothing rotates.
RUN sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    # A silent miss here would leave the server on a port it may not bind, so the
    # rewrite is an assertion rather than a best effort.
    && grep -q '^Listen 8080$' /etc/apache2/ports.conf \
    # Without a server name Apache resolves one per start and logs a warning for it.
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
    && printf '%s\n' \
    '<VirtualHost *:8080>' \
    '    DocumentRoot /var/www/html/public' \
    '    <Directory /var/www/html/public>' \
    '        Options -Indexes +FollowSymLinks' \
    '        AllowOverride All' \
    '        Require all granted' \
    '    </Directory>' \
    '    ErrorLog /proc/self/fd/2' \
    '    CustomLog /proc/self/fd/1 combined' \
    '</VirtualHost>' \
    > /etc/apache2/sites-available/000-default.conf

# The application, without the parts a build produces: dependencies and webroot
# come from the builder stages, everything below public/ is source.
COPY app ./app
COPY packages ./packages
COPY autoload.php pagekit .htaccess ./
COPY --from=composer-deps /var/www/html/app/vendor ./app/vendor
COPY --from=assets /build/public ./public

# The front controller and the server configuration are committed, not built:
# taking them from the context keeps the repository their single source.
COPY public/index.php public/.htaccess ./public/
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# Only the state directories belong to www-data; the application tree stays
# root-owned and read-only, so a compromised request cannot rewrite the code it
# is served from. config.php is a link into the data directory: the settings
# screens and the installer write it at runtime, and an image is not the place
# for that. It dangles until an installation creates the file.
RUN set -eux; \
    mkdir -p tmp/cache tmp/logs tmp/packages tmp/sessions tmp/temp storage \
    "$PAGEKIT_DATA_DIR" /var/run/apache2 /var/lock/apache2; \
    ln -sfn ../storage public/storage; \
    ln -sfn "$PAGEKIT_DATA_DIR/config.php" config.php; \
    chown -R www-data:www-data tmp storage "$PAGEKIT_DATA_DIR"; \
    chown www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2; \
    # The USER below is the same account by number, which is what a host reading
    # the image sees. Should a base image ever renumber www-data, the build has
    # to fail here rather than hand out an account that owns none of the above.
    [ "$(id -u www-data)" = 33 ]; \
    [ "$(id -g www-data)" = 33 ]

LABEL org.opencontainers.image.title="Pagekit" \
    org.opencontainers.image.description="Pagekit CMS production runtime (Apache + PHP)" \
    org.opencontainers.image.source="https://github.com/Shadesman5/pagekit" \
    org.opencontainers.image.licenses="MIT"

# www-data, by the number an orchestrator can check against a runAsNonRoot policy
# without a copy of the image's passwd file.
USER 33:33

EXPOSE 8080

# The front controller answering at all is the liveness signal. curl fails on
# 4xx/5xx only, so the redirect to HTTPS that a direct HTTP request receives
# still counts as healthy.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD ["curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1:8080/"]

ENTRYPOINT ["entrypoint.sh"]

CMD ["apache2-foreground"]
