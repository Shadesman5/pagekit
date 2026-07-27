<?php

declare(strict_types=1);

namespace Pagekit\Filter;

/**
 * This filter adds a rel="nofollow" to all HTML anchor elements.
 */
class AddRelNofollowFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    public function filter(mixed $value): ?string
    {
        // Null bytes have no legitimate place in an anchor tag and are a classic
        // obfuscation vector: browsers may silently drop them (rendering
        // "<\0a\0 href=...>" as a live link) while a naive matcher skips the tag
        // and never adds nofollow. Strip them before matching so the anchor is
        // normalised and cannot slip through.
        $value = str_replace("\0", '', (string) $value);

        // Match "<a" followed by a slash or any whitespace so slash-obfuscated
        // openings ("<a/href=...>") are covered alongside the normal "<a href=...>".
        return preg_replace_callback('#<a[/\s]([^>]*)>#i', static function (array $matches): string {
            $attributes = (string) $matches[1];

            // Drop any pre-existing rel attribute (double-quoted, single-quoted or
            // bare) so a spoofed rel="follow" or a duplicate rel="nofollow" cannot
            // survive — it is always re-added below.
            $attributes = preg_replace('#(?:^|\s)rel\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $attributes);
            $attributes = trim((string) $attributes);

            return $attributes === '' ? '<a rel="nofollow">' : sprintf('<a %s rel="nofollow">', $attributes);
        }, $value);
    }
}
