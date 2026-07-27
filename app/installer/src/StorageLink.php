<?php

declare(strict_types=1);

namespace Pagekit\Installer;

use Pagekit\Filesystem\Path;

/**
 * The link that puts the media library inside the webroot.
 *
 * Uploads are written to a directory outside the document root and reach the
 * browser through a link in it. A checkout gets that link from the build; an
 * installation unpacked from an archive has to create it here, because archives
 * carry no links.
 */
final class StorageLink
{
    public function __construct(
        private readonly string $path,
        private readonly string $publicPath,
        private readonly string $storagePath,
    ) {
    }

    /**
     * Location of the link, or null when the media library lies outside the
     * application root and cannot be linked into the webroot at all.
     */
    public function getPath(): ?string
    {
        $link = $this->link();

        return $link === null ? null : $link[0];
    }

    /**
     * Whether the webroot already holds the link. A link pointing nowhere counts
     * as well: it occupies the name, and whatever it was meant to reach is the
     * administrator's to fix.
     */
    public function exists(): bool
    {
        $link = $this->getPath();

        return $link !== null && (is_link($link) || file_exists($link));
    }

    /**
     * Creates the link unless the webroot already holds it.
     *
     * @return bool whether the media library is reachable from the webroot
     */
    public function ensure(): bool
    {
        $link = $this->link();

        if ($link === null) {
            return false;
        }

        if ($this->exists()) {
            return true;
        }

        // Shared hosts commonly disable symlink() outright, and creating a link
        // can be refused by the platform even where the function exists. Either
        // outcome costs the media its URLs and nothing else, which is not worth
        // failing an otherwise complete installation over.
        if (!function_exists('symlink')) {
            return false;
        }

        [$linkPath, $target] = $link;
        $parent = dirname($linkPath);

        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            return false;
        }

        return @symlink($target, $linkPath);
    }

    /**
     * States why the media library is not reachable from the webroot and how to
     * link it by hand. Meaningful once ensure() has returned false.
     */
    public function getProblem(): string
    {
        $link = $this->link();

        if ($link === null) {
            return sprintf(
                'The media library "%s" lies outside the application and cannot be linked into the webroot; serve it through a webserver alias instead.',
                $this->storagePath
            );
        }

        [$linkPath, $target] = $link;

        return sprintf(
            'The media library is not linked into the webroot, uploads stay unreachable until the link is created by hand: ln -s %s %s',
            $target,
            $linkPath
        );
    }

    /**
     * Where the link belongs and what it points at, or null when the media
     * library lies outside the application root: its URLs are derived from that
     * relative position, so a link anywhere else would not answer them.
     *
     * @return array{0: string, 1: string}|null
     */
    private function link(): ?array
    {
        $root = Path::directory($this->path);
        $storage = Path::directory($this->storagePath);

        if (strpos($storage, $root) !== 0) {
            return null;
        }

        $relative = trim(substr($storage, strlen($root)), '/');

        if ($relative === '') {
            return null;
        }

        // A relative target keeps the link intact when the installation is moved:
        // one level out of the webroot, plus one per directory the media library
        // is nested in.
        $target = str_repeat('../', substr_count($relative, '/') + 1).$relative;

        return [Path::directory($this->publicPath).$relative, $target];
    }
}
