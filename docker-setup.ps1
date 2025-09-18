# PowerShell Script for Docker Environment Setup
# This script generates secure passwords for the Docker environment

Write-Host "Pagekit Docker Environment Setup" -ForegroundColor Cyan
Write-Host "================================" -ForegroundColor Cyan

# Check if docker.env already exists
if (Test-Path "docker.env") {
    Write-Host "`nWarning: docker.env already exists!" -ForegroundColor Yellow
    $response = Read-Host "Do you want to overwrite it? (y/N)"
    if ($response -ne "y") {
        Write-Host "Setup cancelled." -ForegroundColor Red
        exit
    }
}

# Generate secure passwords
function Generate-SecurePassword {
    # Avoid $ and other problematic characters for Docker environment variables
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*+=_-"
    $password = ""
    for ($i = 0; $i -lt 20; $i++) {
        $password += $chars[(Get-Random -Maximum $chars.Length)]
    }
    return $password
}

$dbPassword = Generate-SecurePassword
$rootPassword = Generate-SecurePassword

Write-Host "`nGenerating secure passwords..." -ForegroundColor Green

# Copy from example file if it exists
if (Test-Path "docker.env.example") {
    $content = Get-Content "docker.env.example" -Raw
} else {
    # Fallback content if example doesn't exist
    $content = @"
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
"@
}

# Escape $ characters for Docker Compose
$dbPasswordEscaped = $dbPassword -replace '\$', '$$$$'
$rootPasswordEscaped = $rootPassword -replace '\$', '$$$$'

# Replace placeholders with secure passwords
$content = $content -replace "DB_PASSWORD=.*", "DB_PASSWORD=$dbPasswordEscaped"
$content = $content -replace "MYSQL_ROOT_PASSWORD=.*", "MYSQL_ROOT_PASSWORD=$rootPasswordEscaped"

# Write to docker.env
$content | Out-File -FilePath "docker.env" -Encoding utf8

Write-Host "`nSetup completed successfully!" -ForegroundColor Green
Write-Host "`nGenerated passwords have been saved to docker.env" -ForegroundColor Cyan
Write-Host "DB Password: $dbPassword" -ForegroundColor Gray
Write-Host "Root Password: $rootPassword" -ForegroundColor Gray
if ($dbPassword -match '\$' -or $rootPassword -match '\$') {
    Write-Host "`nNote: $ characters have been automatically escaped for Docker Compose" -ForegroundColor Yellow
}
Write-Host "`nIMPORTANT: Keep these passwords secure and never commit docker.env to version control!" -ForegroundColor Yellow

# Check if .gitignore exists and add docker.env if not present
if (Test-Path ".gitignore") {
    $gitignoreContent = Get-Content ".gitignore"
    if ($gitignoreContent -notcontains "docker.env") {
        Add-Content ".gitignore" "`ndocker.env"
        Write-Host "`nAdded docker.env to .gitignore" -ForegroundColor Green
    }
}

Write-Host "`nYou can now run: docker-compose up -d" -ForegroundColor Cyan
