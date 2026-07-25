#!/bin/bash
# Generates .env for the Docker development stack with random database passwords.
set -eu

echo -e "\033[36mPagekit Docker Environment Setup\033[0m"
echo -e "\033[36m================================\033[0m"

if [ ! -f ".env.example" ]; then
    echo -e "\n\033[31mError: .env.example not found - run this script from the project root.\033[0m"
    exit 1
fi

if [ -f ".env" ]; then
    echo -e "\n\033[33mWarning: .env already exists!\033[0m"
    read -r -p "Do you want to overwrite it? (y/N): " response || response=""
    if [[ ! "$response" =~ ^[Yy]$ ]]; then
        echo -e "\033[31mSetup cancelled.\033[0m"
        exit 1
    fi
fi

# The charset leaves out '$': Compose would read it as a variable reference in .env.
generate_password() {
    LC_ALL=C tr -dc 'a-zA-Z0-9!@#%^&*_-' < /dev/urandom | head -c 20
}

DB_PASSWORD=$(generate_password)
ROOT_PASSWORD=$(generate_password)

echo -e "\n\033[32mGenerating secure passwords...\033[0m"

# Copied line by line rather than via sed, so that shell metacharacters in the
# generated passwords cannot be reinterpreted.
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
        MYSQL_PASSWORD=*) printf '%s\n' "MYSQL_PASSWORD=$DB_PASSWORD" ;;
        MYSQL_ROOT_PASSWORD=*) printf '%s\n' "MYSQL_ROOT_PASSWORD=$ROOT_PASSWORD" ;;
        *) printf '%s\n' "$line" ;;
    esac
done < .env.example > .env

echo -e "\n\033[32mSetup completed successfully!\033[0m"
echo -e "\n\033[36mGenerated passwords have been saved to .env\033[0m"
echo -e "\033[90mDB Password:   $DB_PASSWORD\033[0m"
echo -e "\033[90mRoot Password: $ROOT_PASSWORD\033[0m"
echo -e "\n\033[33mIMPORTANT: Keep these passwords secure and never commit .env to version control!\033[0m"

echo -e "\n\033[36mYou can now run: docker compose up -d\033[0m"
