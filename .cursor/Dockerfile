FROM php:8.4-cli

# Install system dependencies and development tools
RUN apt-get update && apt-get install -y \
    git \
    curl \
    wget \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libgd-dev \
    sqlite3 \
    libsqlite3-dev \
    nodejs \
    npm \
    vim \
    nano \
    htop \
    # Additional tools for Pagekit modernization
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Install Yarn (required for Pagekit frontend builds)
RUN npm install -g yarn@1.22

# Install GitHub CLI for PR creation
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
    && chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null \
    && apt-get update \
    && apt-get install gh -y \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Pagekit
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_mysql \
    pdo_sqlite \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    xml \
    dom \
    xmlwriter \
    simplexml

# Install Xdebug for code coverage
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Configure PHP for development
RUN echo "memory_limit=256M" > /usr/local/etc/php/conf.d/memory.ini \
    && echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/memory.ini \
    && echo "xdebug.mode=coverage" > /usr/local/etc/php/conf.d/xdebug.ini

# Install Composer 2.0+ using the official installer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Verify composer installation and add to PATH
RUN composer --version && echo 'export PATH="/usr/local/bin:$PATH"' >> /etc/bash.bashrc

# Background agent will use scripts from the checked-out repository
# No need to copy scripts - they're already in the git repo

# Set working directory to workspace root
WORKDIR /workspace

# Environment variables for better git/composer experience
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1

# The background agent will mount the workspace at /workspace
# Dependencies will be installed by the background agent using the install.sh script

# Note: GitHub token should be provided via environment variable when running the container:
# docker run -e PAGEKIT_BACKGROUND_AGENT=your_token ...
