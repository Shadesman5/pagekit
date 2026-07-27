# Generates .env for the Docker development stack with random database passwords.
$ErrorActionPreference = "Stop"

Write-Host "Pagekit Docker Environment Setup" -ForegroundColor Cyan
Write-Host "================================" -ForegroundColor Cyan

if (-not (Test-Path ".env.example")) {
    Write-Host "`nError: .env.example not found - run this script from the project root." -ForegroundColor Red
    exit 1
}

if (Test-Path ".env") {
    Write-Host "`nWarning: .env already exists!" -ForegroundColor Yellow
    $response = Read-Host "Do you want to overwrite it? (y/N)"
    if ($response -notmatch '^[Yy]$') {
        Write-Host "Setup cancelled." -ForegroundColor Red
        exit 1
    }
}

# The charset leaves out '$': Compose would read it as a variable reference in .env.
function New-SecurePassword {
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*+=_-"
    $password = ""
    for ($i = 0; $i -lt 20; $i++) {
        $password += $chars[(Get-Random -Maximum $chars.Length)]
    }
    return $password
}

$dbPassword = New-SecurePassword
$rootPassword = New-SecurePassword

Write-Host "`nGenerating secure passwords..." -ForegroundColor Green

$content = Get-Content ".env.example" | ForEach-Object {
    switch -Regex ($_) {
        '^MYSQL_PASSWORD=' { "MYSQL_PASSWORD=$dbPassword" }
        '^MYSQL_ROOT_PASSWORD=' { "MYSQL_ROOT_PASSWORD=$rootPassword" }
        default { $_ }
    }
}

# WriteAllLines writes UTF-8 without a BOM; a BOM would end up inside the name of
# the first variable and break Compose's .env parsing.
[System.IO.File]::WriteAllLines((Join-Path $PWD.Path ".env"), $content)

Write-Host "`nSetup completed successfully!" -ForegroundColor Green
Write-Host "`nGenerated passwords have been saved to .env" -ForegroundColor Cyan
Write-Host "DB Password:   $dbPassword" -ForegroundColor Gray
Write-Host "Root Password: $rootPassword" -ForegroundColor Gray
Write-Host "`nIMPORTANT: Keep these passwords secure and never commit .env to version control!" -ForegroundColor Yellow

Write-Host "`nYou can now run: docker compose up -d" -ForegroundColor Cyan
