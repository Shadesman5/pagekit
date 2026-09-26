<?php

declare(strict_types=1);

namespace Pagekit\Package\Archive;

use PhpParser\Error as SyntaxError;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * A package ZIP that passed every check the install relies on.
 *
 * @phpstan-type Entry array{name: string, path: string, directory: bool, size: int, crc: int}
 */
final class PackageArchive
{
    /** A composer package name, "vendor/name" in lower case. */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*\z/';

    /** The snapshot store's id alphabet, so a version can be part of a snapshot id. */
    public const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\z/';

    public const TYPES = ['pagekit-extension', 'pagekit-theme'];

    /** What all entries together may declare they unpack to. */
    public const MAX_UNCOMPRESSED_BYTES = 512 * 1024 * 1024;

    /** composer.json and index.php are read into memory whole, so they get a bound of their own. */
    private const MANIFEST_MAX_BYTES = 1024 * 1024;

    private const CHUNK_BYTES = 64 * 1024;

    /**
     * @param list<Entry>             $entries
     * @param array<array-key, mixed> $composer
     * @param array<string, string>   $autoload
     * @param list<string>            $require
     */
    private function __construct(
        private readonly string $path,
        private readonly array $entries,
        private readonly array $composer,
        private readonly string $name,
        private readonly string $type,
        private readonly string $version,
        private readonly string $title,
        private readonly array $autoload,
        private readonly array $require,
    ) {
    }

    /**
     * Checks a ZIP file for everything the install relies on, writing nothing.
     *
     * @throws ArchiveRefusedException naming the first thing the archive gets wrong
     */
    public static function open(string $file): self
    {
        $zip = new \ZipArchive();

        if (!is_file($file) || $zip->open($file, \ZipArchive::RDONLY) !== true) {
            throw new ArchiveRefusedException(__('The file is not a readable ZIP archive.'));
        }

        try {
            if ($zip->count() === 0) {
                throw new ArchiveRefusedException(__('The archive is empty.'));
            }

            $entries = self::entries($zip);
            $metadata = self::metadata($zip, $entries);
            $manifest = self::manifest($zip, $entries, basename($metadata['name']));

            return new self(
                $file,
                $entries,
                $metadata['composer'],
                $metadata['name'],
                $metadata['type'],
                $metadata['version'],
                $metadata['title'],
                $manifest['autoload'],
                $manifest['require'],
            );
        } finally {
            $zip->close();
        }
    }

    /** The package name composer.json gives, "vendor/name". */
    public function name(): string
    {
        return $this->name;
    }

    /** The module index.php registers, which is the last part of the package name. */
    public function module(): string
    {
        return basename($this->name);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * What index.php autoloads, keyed by namespace prefix.
     *
     * @return array<string, string> folders relative to the package root
     */
    public function autoload(): array
    {
        return $this->autoload;
    }

    /**
     * Modules index.php requires, in the order the array literal yields them.
     *
     * @return list<string>
     */
    public function require(): array
    {
        return $this->require;
    }

    /**
     * Refusal for a requirement this installation has not registered.
     */
    public function unknownRequirement(string $required): ArchiveRefusedException
    {
        return new ArchiveRefusedException(__('Module "%depender%" requires "%required%", which is not registered.', [
            '%depender%' => $this->module(),
            '%required%' => self::printable($required),
        ]));
    }

    /**
     * composer.json as the archive carries it.
     *
     * @return array<array-key, mixed>
     */
    public function composer(): array
    {
        return $this->composer;
    }

    /** The ZIP file the archive was opened from. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Unpacks the archive into $directory, where none of its paths exist yet.
     *
     * @throws \RuntimeException where the archive can no longer be read or an entry cannot be written
     */
    public function extractTo(string $directory): void
    {
        $zip = new \ZipArchive();

        if ($zip->open($this->path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException(__('The archive can no longer be read.'));
        }

        try {
            // What open() checked is this listing; a file that changed since is one nobody checked.
            if ($zip->count() !== count($this->entries)) {
                throw new \RuntimeException(__('The archive changed after it was checked.'));
            }

            foreach ($this->entries as $index => $entry) {
                $stat = $zip->statIndex($index);

                if ($stat === false || $stat['name'] !== $entry['name'] || $stat['size'] !== $entry['size'] || $stat['crc'] !== $entry['crc']) {
                    throw new \RuntimeException(__('The archive changed after it was checked.'));
                }
            }

            if (!self::makeDirectory($directory)) {
                throw new \RuntimeException(__('The folder to unpack the archive into could not be created.'));
            }

            foreach ($this->entries as $index => $entry) {
                $target = $directory . '/' . $entry['path'];

                if (!self::makeDirectory($entry['directory'] ? $target : dirname($target))) {
                    throw new \RuntimeException(__('"%entry%" could not be unpacked from the archive.', ['%entry%' => self::printable($entry['name'])]));
                }

                if (!$entry['directory']) {
                    self::write($zip, $index, $entry, $target);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * The archive's listing, each entry a plain relative path that no other entry shares.
     *
     * @return list<Entry>
     *
     * @throws ArchiveRefusedException
     */
    private static function entries(\ZipArchive $zip): array
    {
        $entries = [];
        $names = [];
        $folders = [];
        $total = 0;

        for ($index = 0, $count = $zip->count(); $index < $count; ++$index) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw new ArchiveRefusedException(__('The file is not a readable ZIP archive.'));
            }

            $name = $stat['name'];
            $size = $stat['size'];
            $crc = $stat['crc'];
            $directory = str_ends_with($name, '/');
            $path = $directory ? substr($name, 0, -1) : $name;
            $quoted = ['%entry%' => self::printable($name)];

            if (str_contains($name, '\\')) {
                throw new ArchiveRefusedException(__('The archive entry "%entry%" contains a backslash.', $quoted));
            }

            if (
                str_contains($name, "\0")
                || preg_match('/^[A-Za-z]:/', $name) === 1
                || array_intersect(explode('/', $path), ['', '.', '..']) !== []
            ) {
                throw new ArchiveRefusedException(__('The archive entry "%entry%" is not a relative path inside the package.', $quoted));
            }

            $system = 0;
            $attributes = 0;

            if ($zip->getExternalAttributesIndex($index, $system, $attributes) && (($attributes >> 16) & 0170000) === 0120000) {
                throw new ArchiveRefusedException(__('The archive entry "%entry%" is a symbolic link.', $quoted));
            }

            // Extraction writes every entry in turn, so a second one under a name the first
            // already holds - to a filesystem that ignores case, too - replaces what was checked.
            $key = strtolower($path);

            if (isset($names[$key])) {
                throw new ArchiveRefusedException(__('The archive holds "%entry%" more than once.', $quoted));
            }

            $names[$key] = true;

            for ($parent = dirname($key); $parent !== '.'; $parent = dirname($parent)) {
                $folders[$parent] = true;
            }

            $total += $size;
            $entries[] = ['name' => $name, 'path' => $path, 'directory' => $directory, 'size' => $size, 'crc' => $crc];
        }

        if ($total > self::MAX_UNCOMPRESSED_BYTES) {
            throw new ArchiveRefusedException(__('The archive unpacks to more than %size% MiB.', ['%size%' => (string) intdiv(self::MAX_UNCOMPRESSED_BYTES, 1024 * 1024)]));
        }

        foreach ($entries as $entry) {
            if (!$entry['directory'] && isset($folders[strtolower($entry['path'])])) {
                throw new ArchiveRefusedException(__('The archive holds "%entry%" both as a file and as a folder.', ['%entry%' => self::printable($entry['name'])]));
            }
        }

        return $entries;
    }

    /**
     * composer.json at the archive's root, holding what the install is keyed by.
     *
     * @param list<Entry> $entries
     *
     * @return array{composer: array<array-key, mixed>, name: string, type: string, version: string, title: string}
     *
     * @throws ArchiveRefusedException
     */
    private static function metadata(\ZipArchive $zip, array $entries): array
    {
        $json = self::read($zip, $entries, 'composer.json');

        try {
            $composer = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $composer = null;
        }

        // A JSON list decodes to a PHP array just as an object does.
        if (!is_array($composer) || !str_starts_with(ltrim($json, " \t\n\r"), '{')) {
            throw new ArchiveRefusedException(__('The archive\'s composer.json is not a JSON object.'));
        }

        $name = $composer['name'] ?? null;

        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new ArchiveRefusedException(__('The archive\'s composer.json gives no valid "name": it has to be "vendor/package" in lower case.'));
        }

        $type = $composer['type'] ?? null;

        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new ArchiveRefusedException(__('The archive\'s composer.json gives a "type" other than "pagekit-extension" or "pagekit-theme".'));
        }

        $version = $composer['version'] ?? null;

        if (is_string($version) && str_contains($version, '+')) {
            throw new ArchiveRefusedException(__('The archive\'s composer.json gives the version "%version%" with build metadata after "+", which is not accepted.', ['%version%' => self::printable($version)]));
        }

        if (!is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new ArchiveRefusedException(__('The archive\'s composer.json gives no valid "version".'));
        }

        $title = $composer['title'] ?? null;

        if (!is_string($title) || trim($title) === '') {
            throw new ArchiveRefusedException(__('The archive\'s composer.json gives no "title".'));
        }

        $extra = $composer['extra'] ?? null;
        $scripts = is_array($extra) ? ($extra['scripts'] ?? null) : null;

        // Any empty value means "no scripts" to the lifecycle runner.
        if (!empty($scripts)) {
            $script = is_string($scripts) ? self::relative($scripts) : null;

            if ($script === null || self::file($entries, $script) === null) {
                throw new ArchiveRefusedException(__('The archive does not contain the lifecycle scripts its composer.json names in "extra.scripts".'));
            }
        }

        return ['composer' => $composer, 'name' => $name, 'type' => $type, 'version' => $version, 'title' => $title];
    }

    /**
     * The autoload map and requirement list index.php declares, read without running the file.
     *
     * @param list<Entry> $entries
     *
     * @return array{autoload: array<string, string>, require: list<string>}
     *
     * @throws ArchiveRefusedException
     */
    private static function manifest(\ZipArchive $zip, array $entries, string $module): array
    {
        $code = self::read($zip, $entries, 'index.php');

        try {
            $statements = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        } catch (SyntaxError $e) {
            throw new ArchiveRefusedException(__('The archive\'s index.php cannot be parsed: %error%', ['%error%' => self::printable($e->getMessage())]), 0, $e);
        }

        // A condition or a goto can make PHP return from a statement other than the one read below,
        // so the file may hold only one return outside its function and class bodies.
        $finder = new NodeFinder();
        $bodies = $finder->find($statements, fn (Node $node): bool => $node instanceof FunctionLike || $node instanceof ClassLike);
        $nested = $finder->findInstanceOf($bodies, Return_::class);
        $returns = array_filter($finder->findInstanceOf($statements, Return_::class), fn (Return_ $return): bool => !in_array($return, $nested, true));

        if (count($returns) > 1) {
            throw new ArchiveRefusedException(__('The archive\'s index.php has more than one return statement outside its functions and classes.'));
        }

        $returned = null;

        foreach ($statements as $statement) {
            // Code under a namespace still runs at file scope. A `namespace X;` node may carry null
            // stmts, and the code after it then follows as siblings this outer loop reaches.
            foreach ($statement instanceof Namespace_ ? (array) $statement->stmts : [$statement] as $inner) {
                if ($inner instanceof Return_) {
                    $returned = $inner->expr;

                    break 2;
                }
            }
        }

        if (!($returned instanceof Array_)) {
            throw new ArchiveRefusedException(__('The archive\'s index.php does not return an array literal at its top level.'));
        }

        // A later key wins when PHP builds the array, and a spread or a computed key can be any
        // key, so only the literal keys after the last of those are certain.
        /** @var array<string, Expr> $items */
        $items = [];

        foreach ($returned->items as $item) {
            if ($item->key instanceof String_) {
                $items[$item->key->value] = $item->value;
            } elseif ($item->unpack || ($item->key !== null && !($item->key instanceof Int_))) {
                $items = [];
            }
        }

        $name = $items['name'] ?? null;

        if (!($name instanceof String_) || $name->value !== $module) {
            throw new ArchiveRefusedException(__('The archive\'s index.php has to give \'name\' => \'%module%\' as a string literal.', ['%module%' => $module]));
        }

        $declared = $items['autoload'] ?? null;

        if (!($declared instanceof Array_)) {
            throw new ArchiveRefusedException(__('The archive\'s index.php gives no \'autoload\' array of string literals.'));
        }

        $autoload = [];

        foreach ($declared->items as $item) {
            if (!($item->key instanceof String_) || !($item->value instanceof String_)) {
                throw new ArchiveRefusedException(__('The archive\'s index.php gives no \'autoload\' array of string literals.'));
            }

            $namespace = $item->key->value;
            $path = $item->value->value;
            $folder = self::relative(strtr($path, '\\', '/'));

            if ($folder === null || !self::folder($entries, $folder)) {
                throw new ArchiveRefusedException(__('The archive\'s index.php autoloads "%namespace%" from "%path%", which is not a folder in the archive.', [
                    '%namespace%' => self::printable($namespace),
                    '%path%' => self::printable($path),
                ]));
            }

            $autoload[$namespace] = $path;
        }

        return [
            'autoload' => $autoload,
            'require' => self::requirementList($items['require'] ?? null),
        ];
    }

    /**
     * Requirement names from a literal list. A missing key is an empty list.
     *
     * @return list<string>
     *
     * @throws ArchiveRefusedException
     */
    private static function requirementList(?Expr $declared): array
    {
        if ($declared === null) {
            return [];
        }

        // The file is not executed. A spread or a non-string can name a module that is not written here.
        if (!$declared instanceof Array_) {
            throw new ArchiveRefusedException(__('The archive\'s index.php gives no \'require\' array of string literals.'));
        }

        // "-0" stays a string key, so the name appended after it is integer 0.
        // From PHP 8.3 a negative integer continues at n+1, so the name after "-4" is -3 and a later "0" does not replace it.
        $values = [];

        foreach ($declared->items as $item) {
            $key = $item->key;

            if ($item->unpack || !$item->value instanceof String_) {
                throw new ArchiveRefusedException(__('The archive\'s index.php gives no \'require\' array of string literals.'));
            }

            if ($key === null) {
                try {
                    $values[] = $item->value->value;
                } catch (\Error $e) {
                    // Past PHP_INT_MAX the literal throws, so there is no list to read.
                    throw new ArchiveRefusedException(__('The archive\'s index.php gives no \'require\' array of string literals.'), 0, $e);
                }

                continue;
            }

            if ($key instanceof String_) {
                $index = $key->value;
            } elseif ($key instanceof Int_) {
                $index = $key->value;
            } else {
                throw new ArchiveRefusedException(__('The archive\'s index.php gives no \'require\' array of string literals.'));
            }

            $values[$index] = $item->value->value;
        }

        return array_values($values);
    }

    /**
     * A manifest file at the archive's root, read into memory to be checked.
     *
     * @param list<Entry> $entries
     *
     * @throws ArchiveRefusedException
     */
    private static function read(\ZipArchive $zip, array $entries, string $file): string
    {
        $index = self::file($entries, $file);

        if ($index === null) {
            throw new ArchiveRefusedException(__('The archive has no %file% at its top level.', ['%file%' => $file]));
        }

        if ($entries[$index]['size'] > self::MANIFEST_MAX_BYTES) {
            throw new ArchiveRefusedException(__('The archive\'s %file% is too large.', ['%file%' => $file]));
        }

        $content = $zip->getFromIndex($index);

        if ($content === false) {
            throw new ArchiveRefusedException(__('The file is not a readable ZIP archive.'));
        }

        return $content;
    }

    /**
     * The index of the file the archive lists at $path.
     *
     * @param list<Entry> $entries
     */
    private static function file(array $entries, string $path): ?int
    {
        foreach ($entries as $index => $entry) {
            if (!$entry['directory'] && $entry['path'] === $path) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether the archive holds $path as a folder, listed or implied by what lies below it.
     *
     * @param list<Entry> $entries
     */
    private static function folder(array $entries, string $path): bool
    {
        if ($path === '') {
            return true;
        }

        foreach ($entries as $entry) {
            if (str_starts_with($entry['path'], $path . '/') || ($entry['directory'] && $entry['path'] === $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A path a manifest gives relative to the package, spelled the way the archive lists it.
     *
     * @return string|null null where the path does not stay inside the package
     */
    private static function relative(string $path): ?string
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return null;
        }

        $segments = array_filter(explode('/', $path), fn (string $segment): bool => $segment !== '' && $segment !== '.');

        return in_array('..', $segments, true) ? null : implode('/', $segments);
    }

    /**
     * Copies one file entry out, held to the size and checksum the listing declares.
     *
     * @param Entry $entry
     *
     * @throws \RuntimeException
     */
    private static function write(\ZipArchive $zip, int $index, array $entry, string $target): void
    {
        $quoted = ['%entry%' => self::printable($entry['name'])];
        $source = $zip->getStreamIndex($index);

        // 'x' refuses a path that exists already, a link planted there included.
        $sink = $source === false ? false : @fopen($target, 'xb');

        if ($source === false || $sink === false) {
            if ($source !== false) {
                fclose($source);
            }

            throw new \RuntimeException(__('"%entry%" could not be unpacked from the archive.', $quoted));
        }

        $crc = hash_init('crc32b');
        $size = 0;
        $written = true;

        try {
            while (($chunk = @fread($source, self::CHUNK_BYTES)) !== false && $chunk !== '') {
                $size += strlen($chunk);

                // The declared size is only a claim, and what decompresses can run past it.
                if ($size > $entry['size']) {
                    break;
                }

                hash_update($crc, $chunk);

                if (@fwrite($sink, $chunk) !== strlen($chunk)) {
                    $written = false;

                    break;
                }
            }
        } finally {
            fclose($source);
            fclose($sink);
        }

        if (!$written) {
            throw new \RuntimeException(__('"%entry%" could not be unpacked from the archive.', $quoted));
        }

        // A read error at the end of an entry reaches the stream as a plain end of data.
        if ($size !== $entry['size'] || hexdec(hash_final($crc)) !== $entry['crc']) {
            throw new \RuntimeException(__('"%entry%" in the archive is damaged.', $quoted));
        }
    }

    /**
     * Creates a folder with the mode the umask leaves, unless it exists already.
     */
    private static function makeDirectory(string $directory): bool
    {
        return is_dir($directory) || @mkdir($directory, 0777, true) || is_dir($directory);
    }

    /**
     * An archive-supplied string made safe to quote in a message read in a terminal or a JSON body.
     */
    private static function printable(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            $value = (string) preg_replace('/[\x80-\xFF]/', '?', $value);
        }

        return (string) preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]/u', '?', $value);
    }
}
