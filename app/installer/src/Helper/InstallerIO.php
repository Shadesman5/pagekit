<?php

declare(strict_types=1);

namespace Pagekit\Installer\Helper;

use Composer\IO\ConsoleIO;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class InstallerIO extends ConsoleIO
{
    public const REGEX = '/((?<=<error>).+(?=<\/error>))|(\[.+Exception\])/';

    /**
     * {@inheritdoc}
     */
    public function __construct(?InputInterface $input = null, ?OutputInterface $output = null, ?HelperSet $helperSet = null)
    {
        $this->input = $input ?: new ArrayInput([]);

        if ($output === null) {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open php://output stream.');
            }
            $output = new StreamOutput($stream);
        }
        $this->output = $output;

        $this->helperSet = $helperSet ?: new HelperSet();

        parent::__construct($this->input, $this->output, $this->helperSet);

        if (PHP_SAPI != 'cli') {

            ob_implicit_flush(true);
            @ob_end_flush();

        }
    }

    /**
     * {@inheritdoc}
     */
    public function writeError($messages, $newline = true, $verbosity = parent::NORMAL): void
    {
        foreach ((array)$messages as $message) {
            if (preg_match(self::REGEX, $message, $matches)) {
                throw new \RuntimeException($matches[0]);
            }
        }

        parent::writeError($messages, $newline, $verbosity);
    }
}
