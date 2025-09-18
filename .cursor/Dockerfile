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

# Install Composer 2.0+
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create ubuntu user for development
RUN useradd -m -s /bin/bash ubuntu

# Create .cursor directory and helper scripts
RUN mkdir -p /home/ubuntu/.cursor \
    && echo '#!/bin/bash\n# Pagekit installation helper script\necho "Installing Pagekit dependencies..."\nif [ -f "composer.json" ]; then\n    composer install --no-dev --optimize-autoloader\nfi\nif [ -f "package.json" ]; then\n    yarn install\n    yarn compile-js\nfi\necho "Installation completed!"' > /home/ubuntu/.cursor/install.sh \
    && echo '#!/bin/bash\n# Pagekit modernization helper script\necho "Running Pagekit modernization tasks..."\n# Add your modernization commands here\necho "Modernization helper ready!"' > /home/ubuntu/.cursor/modernize-helper.sh \
    && echo '#!/bin/bash\n# Pagekit startup script\necho "Starting Pagekit development environment..."\ncd /workspace\n# Run any startup tasks here\nexec "$@"' > /home/ubuntu/.cursor/start.sh \
    && chown -R ubuntu:ubuntu /home/ubuntu/.cursor \
    && chmod +x /home/ubuntu/.cursor/*.sh

# Switch to ubuntu user
USER ubuntu

# Set working directory to user home
WORKDIR /home/ubuntu

# Environment variables for better git/composer experience
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1

# Code will be cloned by the background agent - no COPY needed
# Dependencies will be installed by the background agent - no RUN composer install needed

# Note: GitHub token should be provided via environment variable when running the container:
# docker run -e GH_TOKEN=your_token ...
