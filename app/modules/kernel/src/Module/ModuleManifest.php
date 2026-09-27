<?php

declare(strict_types=1);

namespace Pagekit\Module;

/**
 * The static registration record beside a module entry point.
 */
final class ModuleManifest
{
    public const FILE = 'module.json';

    public const ENTRY = 'index.php';

    /** One mebibyte. Larger documents are refused before they are decoded. */
    public const MAX_BYTES = 1048576;

    /**
     * Whether an include glob stays under the module that declares it.
     */
    public static function includeStaysInModule(string $pattern): bool
    {
        $pattern = strtr($pattern, '\\', '/');

        if ($pattern === '' || str_contains($pattern, "\0")) {
            return false;
        }

        if ($pattern[0] === '/' || self::isDrivePath($pattern)) {
            return false;
        }

        foreach (explode('/', $pattern) as $segment) {
            if (self::segmentMatchesParent($segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Registration fields from one JSON object.
     *
     * Null means the document names no module: discovery skips it and does not
     * record a failure. Unknown keys are ignored.
     *
     * @return array<string, mixed>|null
     *
     * @throws ModuleManifestException invalid JSON, a non-object, or a field of the wrong type
     */
    public static function decode(string $json): ?array
    {
        try {
            $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ModuleManifestException($exception->getMessage(), 0, $exception);
        }

        if (!$document instanceof \stdClass) {
            throw new ModuleManifestException('Module manifest must be a JSON object.');
        }

        return self::fields($document);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fields(\stdClass $document): ?array
    {
        if (!property_exists($document, 'name') || $document->name === '') {
            return null;
        }

        if (!is_string($document->name)) {
            throw new ModuleManifestException('Module manifest field "name" is invalid.');
        }

        $module = ['name' => $document->name];

        if (property_exists($document, 'require')) {
            $require = $document->require;

            if (!is_array($require)) {
                throw new ModuleManifestException('Module manifest field "require" is invalid.');
            }

            $module['require'] = self::strings($require, 'require', false);
        } else {
            $module['require'] = [];
        }

        if (property_exists($document, 'include')) {
            $include = $document->include;

            // json_decode leaves a JSON object as stdClass, so it is not a list.
            if (!is_string($include) && !is_array($include)) {
                throw new ModuleManifestException('Module manifest field "include" is invalid.');
            }

            $module['include'] = self::includePaths($include);
        }

        if (property_exists($document, 'autoload')) {
            $autoload = $document->autoload;

            if (!$autoload instanceof \stdClass) {
                throw new ModuleManifestException('Module manifest field "autoload" is invalid.');
            }

            $module['autoload'] = self::map($autoload);
        }

        if (property_exists($document, 'nodes')) {
            if (!$document->nodes instanceof \stdClass) {
                throw new ModuleManifestException('Module manifest field "nodes" is invalid.');
            }

            $nodes = self::value($document->nodes);

            if (!is_array($nodes)) {
                throw new ModuleManifestException('Module manifest field "nodes" is invalid.');
            }

            $module['nodes'] = $nodes;
        }

        return $module;
    }

    private static function isDrivePath(string $pattern): bool
    {
        if (strlen($pattern) < 2 || $pattern[1] !== ':') {
            return false;
        }

        $drive = $pattern[0];

        return ($drive >= 'A' && $drive <= 'Z') || ($drive >= 'a' && $drive <= 'z');
    }

    /**
     * Whether this path segment can name the parent directory.
     */
    private static function segmentMatchesParent(string $segment): bool
    {
        if ($segment === '..') {
            return true;
        }

        // glob skips "." and ".." unless the pattern itself starts with ".".
        if ($segment === '' || !str_starts_with($segment, '.')) {
            return false;
        }

        return self::globMatchesParent($segment);
    }

    private static function globMatchesParent(string $pattern): bool
    {
        $subject = '..';
        $end = strlen($subject);
        /** @var array<int, true> $at */
        $at = [0 => true];
        $size = strlen($pattern);
        $index = 0;

        while ($index < $size) {
            $char = $pattern[$index];

            if ($char === '*') {
                $at = self::expandStar($at, $end);
                $index++;

                continue;
            }

            if ($char === '?') {
                $at = self::advanceAny($at, $end);
                $index++;

                continue;
            }

            if ($char === '[') {
                $class = self::bracketAtom($pattern, $index);

                // A collating form can still name ".."; refusing it keeps the guarantee.
                if ($class === null || $class['length'] < 1) {
                    return true;
                }

                $at = self::advanceClass($at, $subject, $class['negate'], $class['ranges']);
                $index += $class['length'];

                continue;
            }

            $at = self::advanceClass($at, $subject, false, [[$char, $char]]);
            $index++;
        }

        return isset($at[$end]);
    }

    /**
     * @param array<int, true> $reachable
     *
     * @return array<int, true>
     */
    private static function expandStar(array $reachable, int $end): array
    {
        $start = $end + 1;

        foreach (array_keys($reachable) as $position) {
            if ($position < $start) {
                $start = $position;
            }
        }

        if ($start > $end) {
            return [];
        }

        $next = [];

        for ($position = $start; $position <= $end; $position++) {
            $next[$position] = true;
        }

        return $next;
    }

    /**
     * @param array<int, true> $reachable
     *
     * @return array<int, true>
     */
    private static function advanceAny(array $reachable, int $end): array
    {
        $next = [];

        foreach (array_keys($reachable) as $position) {
            if ($position < $end) {
                $next[$position + 1] = true;
            }
        }

        return $next;
    }

    /**
     * @param array<int, true>                  $reachable
     * @param list<array{0: string, 1: string}> $ranges
     *
     * @return array<int, true>
     */
    private static function advanceClass(array $reachable, string $subject, bool $negate, array $ranges): array
    {
        $next = [];
        $end = strlen($subject);

        foreach (array_keys($reachable) as $position) {
            if ($position < $end && self::inRanges($subject[$position], $negate, $ranges)) {
                $next[$position + 1] = true;
            }
        }

        return $next;
    }

    /**
     * @param list<array{0: string, 1: string}> $ranges
     */
    private static function inRanges(string $char, bool $negate, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($start <= $char && $char <= $end) {
                return !$negate;
            }
        }

        return $negate;
    }

    /**
     * Bracket expression at $start, or null when its form can still match "..".
     *
     * @return array{length: int, negate: bool, ranges: list<array{0: string, 1: string}>}|null
     */
    private static function bracketAtom(string $pattern, int $start): ?array
    {
        $size = strlen($pattern);
        $index = $start + 1;

        if ($index >= $size) {
            return ['length' => 1, 'negate' => false, 'ranges' => [['[', '[']]];
        }

        $negate = false;

        if ($pattern[$index] === '!' || $pattern[$index] === '^') {
            $negate = true;
            $index++;
        }

        if ($index >= $size) {
            return ['length' => 1, 'negate' => false, 'ranges' => [['[', '[']]];
        }

        /** @var list<array{0: string, 1: string}> $ranges */
        $ranges = [];
        $first = true;

        while ($index < $size) {
            if ($pattern[$index] === ']' && !$first) {
                return ['length' => $index + 1 - $start, 'negate' => $negate, 'ranges' => $ranges];
            }

            if (
                $pattern[$index] === '['
                && $index + 1 < $size
                && ($pattern[$index + 1] === '.' || $pattern[$index + 1] === ':' || $pattern[$index + 1] === '=')
            ) {
                return null;
            }

            $first = false;
            $from = $pattern[$index];
            $index++;

            if ($index + 1 < $size && $pattern[$index] === '-' && $pattern[$index + 1] !== ']') {
                $to = $pattern[$index + 1];
                $index += 2;

                if ($from <= $to) {
                    $ranges[] = [$from, $to];
                }

                continue;
            }

            $ranges[] = [$from, $from];
        }

        return ['length' => 1, 'negate' => false, 'ranges' => [['[', '[']]];
    }

    /**
     * A non-empty string, or a list of non-empty strings.
     *
     * @param string|array<array-key, mixed> $value
     *
     * @return string|list<string>
     */
    private static function includePaths(string|array $value): string|array
    {
        if (is_string($value)) {
            if ($value === '') {
                throw new ModuleManifestException('Module manifest field "include" is invalid.');
            }

            return $value;
        }

        return self::strings($value, 'include', true);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<string>
     */
    private static function strings(array $value, string $field, bool $nonEmpty): array
    {
        $names = [];

        foreach ($value as $item) {
            if (!is_string($item) || ($nonEmpty && $item === '')) {
                throw new ModuleManifestException(sprintf('Module manifest field "%s" is invalid.', $field));
            }

            $names[] = $item;
        }

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private static function map(\stdClass $value): array
    {
        $map = [];

        foreach (get_object_vars($value) as $prefix => $path) {
            if (!is_string($path)) {
                throw new ModuleManifestException('Module manifest field "autoload" is invalid.');
            }

            $map[$prefix] = $path;
        }

        return $map;
    }

    /**
     * JSON objects become arrays. Scalars pass through.
     *
     * @param array<array-key, mixed>|\stdClass|string|int|float|bool|null $value
     *
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    private static function value(array|\stdClass|string|int|float|bool|null $value): array|string|int|float|bool|null
    {
        if (!$value instanceof \stdClass && !is_array($value)) {
            return $value;
        }

        $items = $value instanceof \stdClass ? get_object_vars($value) : $value;
        /** @var array<array-key, mixed> $stored */
        $stored = [];

        foreach ($items as $key => $item) {
            if (
                !is_array($item)
                && !$item instanceof \stdClass
                && !is_string($item)
                && !is_int($item)
                && !is_float($item)
                && !is_bool($item)
                && $item !== null
            ) {
                throw new ModuleManifestException('Module manifest field "nodes" is invalid.');
            }

            $stored[$key] = self::value($item);
        }

        return $stored;
    }
}
