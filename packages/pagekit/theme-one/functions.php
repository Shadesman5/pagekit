<?php

declare(strict_types=1);

use Pagekit\Application\UrlProvider;

// Static URL provider for template helper functions.
// TODO: TEMPORARY BRIDGE - To be removed in Step 2.5 (Extension Safety & Fault Isolation) —
// replace ThemeOneHelpers static UrlProvider (global state) with proper DI once template helper functions support injection.
final class ThemeOneHelpers
{
    private static ?UrlProvider $url = null;

    public static function setUrl(UrlProvider $url): void
    {
        self::$url = $url;
    }

    public static function getUrl(): UrlProvider
    {
        if (self::$url === null) {
            throw new \RuntimeException('ThemeOneHelpers::setUrl() must be called before using template helpers.');
        }

        return self::$url;
    }
}

function isHTML(string $string): bool
{
    preg_match("/<\/?\w+((\s+\w+(\s*=\s*(?:\".*?\"|'.*?'|[^'\">\s]+))?)+\s*|\s*)\/?>/", $string, $matches);

    return (bool) count($matches);
}

function getHTML(string $content): string
{
    return isHTML($content) ? $content : '<p>'.$content.'</p>';
}

/**
 * @param array<int|string, mixed> $attrs
 */
function attrs(array $attrs): string
{
    $output = [];

    if (count($args = func_get_args()) > 1) {
        $attrs = call_user_func_array('array_merge_recursive', $args);
    }

    foreach ($attrs as $key => $value) {

        if (is_array($value)) {
            $value = implode(' ', array_filter($value));
        }

        if (empty($value) && !is_numeric($value)) {
            continue;
        }

        if (is_numeric($key)) {
            $output[] = $value;
        } elseif ($value === true) {
            $output[] = $key;
        } elseif ($value !== '') {
            $output[] = sprintf('%s="%s"', $key, htmlspecialchars($value, ENT_COMPAT, 'UTF-8', false));
        }
    }

    return (bool) count($output) ? ' '.implode(' ', $output) : '';
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function bgImage(string $url, array $options): array
{

    $attrs = [];
    $attrs['data-src'][] = $options['image'];
    $attrs['uk-img'] = true;

    $attrs['class'][] = 'uk-background-norepeat';
    $attrs['class'][] = "uk-background-cover";

    if (isset($options['image_position']) && $options['image_position']) {
        $attrs['class'][] = "uk-background-{$options['image_position']}";
    }

    if (!isset($options['effect'])) {
        $options['effect'] = '';
    }

    switch ($options['effect']) {
        case '':
            break;
        case 'fixed':
            $attrs['class'][] = 'uk-background-fixed';

            break;
        case 'parallax':
            $parallax_options = [];
            $parallax_options[] = "bgy: -200";
            $parallax_options[] = "media: @s";
            $attrs['uk-parallax'] = implode(';', array_filter($parallax_options));

            break;
    }

    return $attrs;

}

/**
 * @param array<int|string, mixed> $attrs
 */
function image(string $url, array $attrs = []): string
{
    $path = ThemeOneHelpers::getUrl()->get($url);

    if (empty($attrs['alt'])) {
        $attrs['alt'] = true;
    }

    $attributes = attrs(['src' => $path], $attrs);

    return "<img".$attributes.">";
}

function isImage(string $link): string|false
{
    return $link && preg_match('#\.(gif|png|jpe?g|svg)$#', $link, $matches) ? $matches[1] : false;
}
