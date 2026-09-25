<?php

declare(strict_types=1);

namespace Pagekit\Console\Commands;

use Pagekit\Application\Console\Command;
use Pagekit\Package\Archive\PackageArchive;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Gitignore;

class ArchiveCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected ?string $name = 'archive';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Archives an extension or theme';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Package name');
        $this->addOption('dir', null, InputOption::VALUE_OPTIONAL, 'Write the archive to this directory');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->argument('name');

        if (!is_string($name)) {
            throw new \LogicException('Argument "name" must be a string.');
        }

        // The name is joined into a path, so one like "pagekit/../blog" is refused before anything is read.
        if (preg_match(PackageArchive::NAME_PATTERN, $name) !== 1) {
            $this->error('The package name has to be "vendor/name" in lower case.');

            return Command::FAILURE;
        }

        $source = realpath($this->container->get('path.packages') . '/' . $name);

        if ($source === false || !is_dir($source)) {
            $this->error(sprintf('Package \'%s\' doesn\'t exist.', $name));

            return Command::FAILURE;
        }

        $gitignore = is_file($source . '/.gitignore') ? @file_get_contents($source . '/.gitignore') : '';
        $json = is_file($source . '/composer.json') ? @file_get_contents($source . '/composer.json') : '{}';

        if ($gitignore === false || $json === false) {
            $this->error(sprintf('The .gitignore or composer.json of package \'%s\' cannot be read.', $name));

            return Command::FAILURE;
        }

        try {
            $composer = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error(sprintf('The composer.json of package \'%s\' is not valid JSON: %s', $name, $e->getMessage()));

            return Command::FAILURE;
        }

        // A JSON list decodes to a PHP array just as an object does.
        if (!is_array($composer) || !str_starts_with(ltrim($json, " \t\n\r"), '{')) {
            $this->error(sprintf('The composer.json of package \'%s\' is not a JSON object.', $name));

            return Command::FAILURE;
        }

        $archive = $composer['archive'] ?? null;
        // Looked up by key rather than with ??, which would read an exclude given as null as no rules.
        $exclude = is_array($archive) && array_key_exists('exclude', $archive) ? $archive['exclude'] : [];

        if (!is_array($exclude) || !array_is_list($exclude)) {
            $this->error(sprintf('The composer.json of package \'%s\' gives an "archive.exclude" that is not a list of strings.', $name));

            return Command::FAILURE;
        }

        $sources = [$gitignore];

        foreach ($exclude as $rule) {
            if (!is_string($rule)) {
                $this->error(sprintf('The composer.json of package \'%s\' gives an "archive.exclude" that is not a list of strings.', $name));

                return Command::FAILURE;
            }

            $sources[] = $rule;
        }

        $rules = [];

        // One rule per toRegex() call: handed several, it applies git's negation, under which "!/app"
        // re-includes app itself and nothing below it, while archive.exclude was written for a flat
        // list in which the same rule re-includes everything under app/.
        foreach ($sources as $text) {
            foreach (preg_split('/\r\n?|\n/', $text) ?: [] as $line) {
                if (trim($line) === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $negated = str_starts_with($line, '!');
                $rules[] = [$line, Gitignore::toRegex($negated ? substr($line, 1) : $line), $negated];
            }
        }

        $this->info(sprintf('Archiving \'%s\'', $name));

        // TODO: Must be refactored in Step 2.8 (Extension Packaging & Prebuilt Assets) - only the
        // package sources end up in the archive. A package's built bundles and its published
        // assets live under public/, outside the directory read here, so the archive ships
        // without them.
        $finder = Finder::create()
            ->files()
            ->in($source)
            ->ignoreDotFiles(false)
            ->ignoreVCS(true)
            // A link leading out of the package would put a file from elsewhere into the archive.
            ->filter(fn (\SplFileInfo $file): bool => !$file->isLink() || str_starts_with((string) $file->getRealPath(), $source . DIRECTORY_SEPARATOR))
            ->sortByName();

        $files = [];

        try {
            foreach ($finder as $file) {
                $path = strtr($file->getRelativePathname(), '\\', '/');

                if ($this->keeps($path, $rules)) {
                    $files[] = [$file->getPathname(), $path];
                }
            }
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($files === []) {
            $this->error(sprintf('Package \'%s\' has no file to archive.', $name));

            return Command::FAILURE;
        }

        $dir = $this->option('dir') ?: $this->container->get('path');

        if (!is_string($dir)) {
            throw new \LogicException('Option "dir" must be a string.');
        }

        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            $this->error(sprintf('Directory \'%s\' cannot be created.', $dir));

            return Command::FAILURE;
        }

        $target = $dir . '/' . strtr($name, '/', '-') . '.zip';

        // Written beside the target and renamed over it, so a run that fails leaves an earlier archive as it was.
        $temp = $target . '.' . bin2hex(random_bytes(4));
        $zip = new \ZipArchive();

        if ($zip->open($temp, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            $this->error(sprintf('Archive \'%s\' cannot be written.', $target));

            return Command::FAILURE;
        }

        $added = true;

        foreach ($files as [$file, $path]) {
            $added = $added && @$zip->addFile($file, $path);
        }

        if (!@$zip->close() || !$added || !@rename($temp, $target)) {
            @unlink($temp);
            $this->error(sprintf('Archive \'%s\' cannot be written.', $target));

            return Command::FAILURE;
        }

        $this->line(sprintf('Archive created: %s (%.2f MB)', basename($target), filesize($target) / 1024 / 1024));

        return Command::SUCCESS;
    }

    /**
     * Whether $path goes into the archive: the last matching rule decides, even below an excluded folder, and no match keeps it.
     *
     * @param list<array{string, string, bool}> $rules each rule as written, its regex, and whether it re-includes
     *
     * @throws \RuntimeException where a rule cannot be matched against $path
     */
    private function keeps(string $path, array $rules): bool
    {
        $keep = true;

        foreach ($rules as [$rule, $regex, $negated]) {
            $match = @preg_match($regex, $path);

            if ($match === false) {
                throw new \RuntimeException(sprintf('Rule \'%s\' cannot be matched against \'%s\': %s', $rule, $path, preg_last_error_msg()));
            }

            if ($match === 1) {
                $keep = $negated;
            }
        }

        return $keep;
    }
}
