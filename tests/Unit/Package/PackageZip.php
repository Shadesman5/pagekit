<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use ZipArchive;

/**
 * Builds a package ZIP the archive checks accept.
 */
final class PackageZip
{
    /**
     * @param array<string, mixed>  $composer
     * @param array<string, string> $files
     */
    public static function write(string $path, array $composer = [], array $files = [], ?string $index = null): void
    {
        $name = $composer['name'] ?? 'pagekit/demo';

        if (!is_string($name) || !str_contains($name, '/')) {
            throw new \InvalidArgumentException('A package fixture needs a vendor/name.');
        }

        $document = array_merge([
            'name' => $name,
            'type' => 'pagekit-extension',
            'version' => '1.2.3',
            'title' => 'Demo',
        ], $composer);

        $module = basename($name);
        $entries = [
            'composer.json' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'index.php' => $index ?? "<?php\n\nreturn [\n    'name' => '{$module}',\n    'autoload' => [],\n];\n",
        ];

        foreach ($files as $entry => $contents) {
            $entries[$entry] = $contents;
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the fixture directory.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Could not create the fixture archive.');
        }

        foreach ($entries as $entry => $contents) {
            if ($zip->addFromString($entry, $contents) !== true) {
                throw new \RuntimeException('Could not add '.$entry.' to the fixture archive.');
            }
        }

        if ($zip->close() !== true) {
            throw new \RuntimeException('Could not close the fixture archive.');
        }
    }
}
