#!/bin/bash
# Shell Script for Docker Environment Setup
# This script generates secure passwords for the Docker environment

echo -e "\033[36mPagekit Docker Environment Setup\033[0m"
echo -e "\033[36m================================\033[0m"

# Check if docker.env already exists
if [ -f "docker.env" ]; then
    echo -e "\n\033[33mWarning: docker.env already exists!\033[0m"
    read -p "Do you want to overwrite it? (y/N): " response
    if [ "$response" != "y" ]; then
        echo -e "\033[31mSetup cancelled.\033[0m"
        exit 1
    fi
fi

# Generate secure passwords
generate_password() {
    # Generate password without problematic characters like $ for Docker
    # Use only alphanumeric and safe special characters
    tr -dc 'a-zA-Z0-9!@#%^&*_-' < /dev/urandom | head -c 20
}

DB_PASSWORD=$(generate_password)
ROOT_PASSWORD=$(generate_password)

echo -e "\n\033[32mGenerating secure passwords...\033[0m"

# Copy from example file if it exists
if [ -f "docker.env.example" ]; then
    cp docker.env.example docker.env
else
    # Create docker.env with default content
    cat > docker.env << EOF
# Docker Environment Configuration for Pagekit

# Database Configuration
DB_HOST=mysql
DB_PORT=3306
DB_NAME=pagekit
DB_USER=pagekit
DB_PASSWORD=CHANGEME_USE_SECURE_PASSWORD

# Application Configuration
APP_ENV=development
APP_DEBUG=true

# URLs
APP_URL=http://localhost:8080
PHPMYADMIN_URL=http://localhost:8081

# MySQL Root Password
MYSQL_ROOT_PASSWORD=CHANGEME_USE_SECURE_ROOT_PASSWORD

# PHP Configuration
PHP_MEMORY_LIMIT=256M
PHP_UPLOAD_MAX_FILESIZE=64M
PHP_POST_MAX_SIZE=64M
EOF
fi

# Escape $ characters for Docker Compose
DB_PASSWORD_ESCAPED=$(echo "$DB_PASSWORD" | sed 's/\$/\$\$/g')
ROOT_PASSWORD_ESCAPED=$(echo "$ROOT_PASSWORD" | sed 's/\$/\$\$/g')

# Replace placeholders with secure passwords
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS
    sed -i '' "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASSWORD_ESCAPED/" docker.env
    sed -i '' "s/MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=$ROOT_PASSWORD_ESCAPED/" docker.env
else
    # Linux
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASSWORD_ESCAPED/" docker.env
    sed -i "s/MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=$ROOT_PASSWORD_ESCAPED/" docker.env
fi

echo -e "\n\033[32mSetup completed successfully!\033[0m"
echo -e "\n\033[36mGenerated passwords have been saved to docker.env\033[0m"
echo -e "\033[90mDB Password: $DB_PASSWORD\033[0m"
echo -e "\033[90mRoot Password: $ROOT_PASSWORD\033[0m"
if [[ "$DB_PASSWORD" == *"$"* ]] || [[ "$ROOT_PASSWORD" == *"$"* ]]; then
    echo -e "\n\033[33mNote: $ characters have been automatically escaped for Docker Compose\033[0m"
fi
echo -e "\n\033[33mIMPORTANT: Keep these passwords secure and never commit docker.env to version control!\033[0m"

# Check if .gitignore exists and add docker.env if not present
if [ -f ".gitignore" ]; then
    if ! grep -q "^docker.env$" .gitignore; then
        echo "" >> .gitignore
        echo "docker.env" >> .gitignore
        echo -e "\n\033[32mAdded docker.env to .gitignore\033[0m"
    fi
fi

echo -e "\n\033[36mYou can now run: docker-compose up -d\033[0m"

# Make the script executable
chmod +x docker-setup.sh
