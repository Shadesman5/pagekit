FROM php:8.3-cli

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

# Install Playwright and dependencies for E2E testing
RUN npm install -g playwright@latest \
    && npx playwright install-deps \
    && npx playwright install chromium

# Install GitHub CLI for PR creation
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
    && chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null \
    && apt-get update \
    && apt-get install gh -y \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Pagekit
# Note: xml, dom, xmlwriter, simplexml are built-in to PHP 8.3
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
    zip

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

# Create a non-root user for the background agent
RUN useradd -m -s /bin/bash ubuntu

# Environment variables for better git/composer experience
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1

# Set PATH to ensure composer and other tools are accessible
ENV PATH="/usr/local/bin:$PATH"

# Switch to non-root user
USER ubuntu

# Set working directory to user's home (Cursor will mount workspace automatically)
WORKDIR /home/ubuntu

# Note: Cursor background agent will automatically mount the workspace
# Dependencies will be installed by install.sh script (works in both Dockerfile and Snapshot environments)
# GitHub token should be provided via environment variable: PAGEKIT_BACKGROUND_AGENT
