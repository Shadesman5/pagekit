<?php

declare(strict_types=1);

namespace Pagekit\Intl\Loader;

use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * @copyright Copyright (c) 2010, Union of RAD http://union-of-rad.org (http://lithify.me/)
 */
class MoFileLoader extends ArrayLoader
{
    /**
     * Magic used for validating the format of a MO file as well as
     * detecting if the machine used to create that file was little endian.
     *
     * @var float
     */
    public const MO_LITTLE_ENDIAN_MAGIC = 0x950412de;

    /**
     * Magic used for validating the format of a MO file as well as
     * detecting if the machine used to create that file was big endian.
     *
     * @var float
     */
    public const MO_BIG_ENDIAN_MAGIC = 0xde120495;

    /**
     * The size of the header of a MO file in bytes.
     *
     * @var int Number of bytes.
     */
    public const MO_HEADER_SIZE = 28;

    /**
     * {@inheritdoc}
     */
    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        if (!is_string($resource)) {
            throw new InvalidResourceException('MO resource must be a file path string.');
        }

        if (!stream_is_local($resource)) {
            throw new InvalidResourceException(sprintf('This is not a local file "%s".', $resource));
        }

        if (!file_exists($resource)) {
            throw new NotFoundResourceException(sprintf('File "%s" not found.', $resource));
        }

        $messages = $this->parse($resource);

        return parent::load($messages, $locale, $domain);
    }

    /**
     * Parses machine object (MO) format, independent of the machine's endian it
     * was created on. Both 32bit and 64bit systems are supported.
     *
     * @return array<int|string, string>
     *
     * @throws InvalidResourceException If stream content has an invalid format.
     */
    protected function parse(string $resource): array
    {
        $stream = fopen($resource, 'r');
        if ($stream === false) {
            throw new InvalidResourceException(sprintf('Unable to open MO resource "%s".', $resource));
        }

        try {
            $stat = fstat($stream);
            if ($stat === false || $stat['size'] < self::MO_HEADER_SIZE) {
                throw new InvalidResourceException("MO stream content has an invalid format.");
            }

            $magic = unpack('V1', $this->readBytes($stream, 4));
            if ($magic === false) {
                throw new InvalidResourceException("MO stream content has an invalid format.");
            }
            $magicValue = current($magic);
            $magic = hexdec(substr(dechex(is_scalar($magicValue) ? (int) $magicValue : 0), -8));

            if ($magic == self::MO_LITTLE_ENDIAN_MAGIC) {
                $isBigEndian = false;
            } elseif ($magic == self::MO_BIG_ENDIAN_MAGIC) {
                $isBigEndian = true;
            } else {
                throw new InvalidResourceException("MO stream content has an invalid format.");
            }

            // formatRevision
            $this->readLong($stream, $isBigEndian);
            $count = $this->readLong($stream, $isBigEndian);
            $offsetId = $this->readLong($stream, $isBigEndian);
            $offsetTranslated = $this->readLong($stream, $isBigEndian);
            // sizeHashes
            $this->readLong($stream, $isBigEndian);
            // offsetHashes
            $this->readLong($stream, $isBigEndian);

            $messages = [];

            for ($i = 0; $i < $count; $i++) {
                $pluralId = null;
                $translated = null;

                fseek($stream, $offsetId + $i * 8);

                $length = $this->readLong($stream, $isBigEndian);
                $offset = $this->readLong($stream, $isBigEndian);

                if ($length < 1) {
                    continue;
                }

                fseek($stream, $offset);
                $singularId = $this->readBytes($stream, $length);

                if (strpos($singularId, "\000") !== false) {
                    list($singularId, $pluralId) = explode("\000", $singularId);
                }

                fseek($stream, $offsetTranslated + $i * 8);
                $length = $this->readLong($stream, $isBigEndian);
                $offset = $this->readLong($stream, $isBigEndian);

                if ($length < 1) {
                    continue;
                }

                fseek($stream, $offset);
                $translatedRaw = $this->readBytes($stream, $length);

                $translated = strpos($translatedRaw, "\000") !== false
                    ? explode("\000", $translatedRaw)
                    : $translatedRaw;

                if (is_array($translated)) {
                    $messages[$singularId] = stripcslashes($translated[0]);
                    if ($pluralId !== null) {
                        $plurals = [];
                        foreach ($translated as $plural => $pluralTranslation) {
                            $plurals[] = sprintf('{%d} %s', $plural, $pluralTranslation);
                        }
                        $messages[$pluralId] = stripcslashes(implode('|', $plurals));
                    }
                } elseif ($singularId !== '') {
                    $messages[$singularId] = stripcslashes($translated);
                }
            }
        } finally {
            fclose($stream);
        }

        return array_filter($messages);
    }

    /**
     * Reads an unsigned long from stream respecting endianess.
     *
     * @param resource $stream
     */
    protected function readLong($stream, bool $isBigEndian): int
    {
        $result = unpack($isBigEndian ? 'N1' : 'V1', $this->readBytes($stream, 4));
        if ($result === false) {
            throw new InvalidResourceException("MO stream content has an invalid format.");
        }
        $value = current($result);
        if (!is_scalar($value)) {
            throw new InvalidResourceException("MO stream content has an invalid format.");
        }

        return (int) substr((string) $value, -8);
    }

    /**
     * @param resource $stream
     */
    private function readBytes($stream, int $length): string
    {
        if ($length < 1) {
            throw new InvalidResourceException("MO stream content has an invalid format.");
        }
        $data = fread($stream, $length);
        if ($data === false) {
            throw new InvalidResourceException("MO stream content has an invalid format.");
        }

        return $data;
    }
}
