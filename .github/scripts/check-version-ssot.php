<?php

declare(strict_types=1);

/**
 * PHP version single-source-of-truth guard.
 *
 * Every place that declares the supported PHP minor version must agree on one
 * value. This guard extracts a MAJOR.MINOR token from each source below and
 * fails (non-zero exit, naming the offending sources) the moment they diverge:
 *
 *   - composer.json      require.php and config.platform.php
 *   - php-tests.yml      the phpunit job matrix
 *   - requirements.php   PagekitRequirements::REQUIRED_PHP_VERSION
 *   - .cursor/Dockerfile FROM php:<tag>
 *   - Dockerfile         FROM php:<tag>
 *   - README.md          the PHP shields.io badge
 *
 * It uses only plain PHP (no autoloader, no vendor) so CI can run it without a
 * composer install.
 *
 * Usage: php .github/scripts/check-version-ssot.php
 * Exit:  0 = sources agree | 1 = drift detected | 2 = a source is missing/unparsable
 */

const EXIT_AGREE = 0;
const EXIT_DRIFT = 1;
const EXIT_UNREADABLE = 2;

/**
 * Reduces any version-ish token (constraint, tag, badge) to its MAJOR.MINOR.
 */
function php_minor(string $raw): string
{
    if (preg_match('/(\d+)\.(\d+)/', $raw, $matches) !== 1) {
        throw new RuntimeException(sprintf('cannot read a MAJOR.MINOR version from "%s"', $raw));
    }

    return $matches[1] . '.' . $matches[2];
}

function read_source(string $path, string $label): string
{
    if (!is_file($path)) {
        throw new RuntimeException(sprintf('%s: file not found (%s)', $label, $path));
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(sprintf('%s: unreadable file (%s)', $label, $path));
    }

    return $contents;
}

/**
 * Returns the token captured by the pattern's single group.
 */
function match_first(string $contents, string $pattern, string $label): string
{
    if (preg_match($pattern, $contents, $matches) !== 1) {
        throw new RuntimeException(sprintf('%s: no PHP version match', $label));
    }

    return $matches[1];
}

/**
 * @return list<array{source: string, raw: string, minor: string}>
 */
function collect_findings(string $root): array
{
    $findings = [];

    $composerRaw = read_source($root . '/composer.json', 'composer.json');

    try {
        $composer = json_decode($composerRaw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException(sprintf('composer.json: invalid JSON (%s)', $error->getMessage()));
    }

    if (!is_array($composer)) {
        throw new RuntimeException('composer.json: unexpected top-level structure');
    }

    $require = $composer['require'] ?? null;
    $requirePhp = is_array($require) ? ($require['php'] ?? null) : null;

    if (!is_string($requirePhp)) {
        throw new RuntimeException('composer.json: missing require.php');
    }

    $findings[] = ['source' => 'composer.json (require.php)', 'raw' => $requirePhp, 'minor' => php_minor($requirePhp)];

    $config = $composer['config'] ?? null;
    $platform = is_array($config) ? ($config['platform'] ?? null) : null;
    $platformPhp = is_array($platform) ? ($platform['php'] ?? null) : null;

    if (!is_string($platformPhp)) {
        throw new RuntimeException('composer.json: missing config.platform.php');
    }

    $findings[] = ['source' => 'composer.json (config.platform.php)', 'raw' => $platformPhp, 'minor' => php_minor($platformPhp)];

    // The matrix key is `php:`; the per-job `php-version:` keys are deliberately
    // not checked here, so a leading `[ \t]*php:` never matches them.
    $workflowRaw = read_source($root . '/.github/workflows/php-tests.yml', '.github/workflows/php-tests.yml');
    $matrixInner = match_first($workflowRaw, '/^[ \t]*php:\s*\[([^\]]*)\]/m', '.github/workflows/php-tests.yml (matrix.php)');
    $matrixCount = preg_match_all('/\d+\.\d+/', $matrixInner, $matrixMatches);

    if (!$matrixCount) {
        throw new RuntimeException('.github/workflows/php-tests.yml: no versions in the php matrix');
    }

    foreach ($matrixMatches[0] as $version) {
        $findings[] = ['source' => '.github/workflows/php-tests.yml (matrix.php)', 'raw' => $version, 'minor' => php_minor($version)];
    }

    $requirementsRaw = read_source($root . '/app/installer/requirements.php', 'app/installer/requirements.php');
    $required = match_first($requirementsRaw, '/REQUIRED_PHP_VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', 'app/installer/requirements.php (REQUIRED_PHP_VERSION)');
    $findings[] = ['source' => 'app/installer/requirements.php (REQUIRED_PHP_VERSION)', 'raw' => $required, 'minor' => php_minor($required)];

    $devDockerRaw = read_source($root . '/.cursor/Dockerfile', '.cursor/Dockerfile');
    $devImage = match_first($devDockerRaw, '/^FROM\s+php:(\S+)/m', '.cursor/Dockerfile (FROM php)');
    $findings[] = ['source' => '.cursor/Dockerfile (FROM php)', 'raw' => $devImage, 'minor' => php_minor($devImage)];

    $dockerRaw = read_source($root . '/Dockerfile', 'Dockerfile');
    $image = match_first($dockerRaw, '/^FROM\s+php:(\S+)/m', 'Dockerfile (FROM php)');
    $findings[] = ['source' => 'Dockerfile (FROM php)', 'raw' => $image, 'minor' => php_minor($image)];

    $readmeRaw = read_source($root . '/README.md', 'README.md');
    $badge = match_first($readmeRaw, '#badge/php-(\d+\.\d+)#', 'README.md (PHP badge)');
    $findings[] = ['source' => 'README.md (PHP badge)', 'raw' => $badge, 'minor' => php_minor($badge)];

    return $findings;
}

try {
    $findings = collect_findings(dirname(__DIR__, 2));
} catch (Throwable $error) {
    fwrite(STDERR, sprintf("ERROR: %s\n", $error->getMessage()));

    exit(EXIT_UNREADABLE);
}

echo "PHP version single-source-of-truth check\n";

foreach ($findings as $finding) {
    printf("  %-48s -> %-6s (raw: %s)\n", $finding['source'], $finding['minor'], $finding['raw']);
}

$distinct = array_values(array_unique(array_column($findings, 'minor')));

if (count($distinct) === 1) {
    printf("\nPASS: all %d sources agree on PHP %s.\n", count($findings), $distinct[0]);

    exit(EXIT_AGREE);
}

// composer.json (require.php) is collected first and is treated as the reference.
$reference = $findings[0]['minor'];
$header = sprintf(
    "\nFAIL: PHP version drift detected (expected PHP %s from %s):\n",
    $reference,
    $findings[0]['source'],
);
fwrite(STDERR, $header);

foreach ($findings as $finding) {
    if ($finding['minor'] !== $reference) {
        fwrite(STDERR, sprintf("  - %s declares PHP %s (raw: %s)\n", $finding['source'], $finding['minor'], $finding['raw']));
    }
}

exit(EXIT_DRIFT);
