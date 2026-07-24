FROM php:8.5-cli

# Install system dependencies and development tools
RUN apt-get update && apt-get install -y \
    # Base agent shell tooling: git/VCS access, privilege escalation, downloads
    git \
    sudo \
    curl \
    wget \
    # libpng-dev: PNG support for the gd build
    libpng-dev \
    # libzip-dev: backend library of the zip extension
    libzip-dev \
    # zip: archive creation from the agent shell (log/artifact hand-off bundles)
    zip \
    # unzip: Composer extracts dist archives with it
    unzip \
    # libfreetype6-dev: FreeType support for the gd build (--with-freetype)
    libfreetype6-dev \
    # libjpeg62-turbo-dev: JPEG support for the gd build (--with-jpeg)
    libjpeg62-turbo-dev \
    # SQLite CLI + headers for inspecting the test/dev database
    sqlite3 \
    libsqlite3-dev \
    # Node toolchain for the frontend build and Playwright
    nodejs \
    npm \
    # Interactive editors/monitoring for manual debugging sessions
    vim \
    nano \
    htop \
    # default-mysql-client: mysql/mysqladmin CLIs to inspect a MySQL-backed dev
    # database (SQLite-backed runs are covered by sqlite3 above)
    default-mysql-client \
    # CLI search/JSON tools assumed by modernization shell workflows and
    # agent verification sweeps (ripgrep = rg, jq)
    ripgrep \
    jq \
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

# PHP extensions on top of the base image, which already bundles pdo, pdo_sqlite,
# sqlite3, mbstring and the XML family (xml, dom, xmlwriter, simplexml).
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    # pdo_mysql: MySQL driver for MySQL-backed test/dev runs
    pdo_mysql \
    # gd: image processing capability for media handling in extensions and themes
    gd \
    # zip: package archive handling (installer, self-updater, filesystem archives)
    zip

# Install PCOV for code coverage (faster than Xdebug; used by CI and cloud agents)
RUN pecl install pcov \
    && docker-php-ext-enable pcov \
    && echo "pcov.enabled=1" > /usr/local/etc/php/conf.d/pcov.ini \
    && echo "pcov.directory=." >> /usr/local/etc/php/conf.d/pcov.ini

# Configure PHP for development (512M aligns with Tester/PHPStan --memory-limit=512M)
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini \
    && echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/memory.ini

# Install Composer 2.0+ using the official installer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Verify composer installation and add to PATH
RUN composer --version && echo 'export PATH="/usr/local/bin:$PATH"' >> /etc/bash.bashrc

# Create a non-root user for the background agent
RUN useradd -m -s /bin/bash ubuntu

# Create workspace directory - Cursor mounts the cloned repo here
RUN mkdir -p /workspace && chown ubuntu:ubuntu /workspace

# Allow the agent to write its own logs and artifacts
RUN mkdir -p /opt/cursor/logs /opt/cursor/artifacts && \
    chown -R ubuntu:ubuntu /opt/cursor

# Environment variables for better git/composer experience
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1

# Set PATH to ensure composer and other tools are accessible
ENV PATH="/usr/local/bin:$PATH"

# Switch to non-root user
USER ubuntu

# Cursor mounts the cloned repository at /workspace - align WORKDIR with that
WORKDIR /workspace

# Note: Cursor background agent mounts the workspace at /workspace
# Dependencies will be installed by install.sh script (works in both Dockerfile and Snapshot environments)
# GitHub token should be provided via environment variable: PAGEKIT_BACKGROUND_AGENT
