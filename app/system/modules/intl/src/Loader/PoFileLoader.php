<?php

declare(strict_types=1);

namespace Pagekit\Intl\Loader;

use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * @copyright Copyright (c) 2010, Union of RAD http://union-of-rad.org (http://lithify.me/)
 * @copyright Copyright (c) 2012, Clemens Tolboom
 */
class PoFileLoader extends ArrayLoader
{
    /**
     * {@inheritdoc}
     */
    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        if (!is_string($resource)) {
            throw new InvalidResourceException('PO resource must be a file path string.');
        }

        if (!stream_is_local($resource)) {
            throw new InvalidResourceException(sprintf('This is not a local file "%s".', $resource));
        }

        if (!file_exists($resource)) {
            throw new NotFoundResourceException(sprintf('File "%s" not found.', $resource));
        }

        $messages = $this->parse($resource) ?? [];

        return parent::load($messages, $locale, $domain);
    }

    /**
     * Parses portable object (PO) format.
     *
     * From http://www.gnu.org/software/gettext/manual/gettext.html#PO-Files
     * we should be able to parse files having:
     *
     * white-space
     * #  translator-comments
     * #. extracted-comments
     * #: reference...
     * #, flag...
     * #| msgid previous-untranslated-string
     * msgid untranslated-string
     * msgstr translated-string
     *
     * extra or different lines are:
     *
     * #| msgctxt previous-context
     * #| msgid previous-untranslated-string
     * msgctxt context
     *
     * #| msgid previous-untranslated-string-singular
     * #| msgid_plural previous-untranslated-string-plural
     * msgid untranslated-string-singular
     * msgid_plural untranslated-string-plural
     * msgstr[0] translated-string-case-0
     * ...
     * msgstr[N] translated-string-case-n
     *
     * The definition states:
     * - white-space and comments are optional.
     * - msgid "" that an empty singleline defines a header.
     *
     * This parser sacrifices some features of the reference implementation the
     * differences to that implementation are as follows.
     * - No support for comments spanning multiple lines.
     * - Translator and extracted comments are treated as being the same type.
     * - Message IDs are allowed to have other encodings as just US-ASCII.
     *
     * Items with an empty id are ignored.
     *
     * @return array<int|string, string>|null
     */
    protected function parse(string $resource): ?array
    {
        $stream = fopen($resource, 'r');
        if ($stream === false) {
            throw new InvalidResourceException(sprintf('Unable to open PO resource "%s".', $resource));
        }

        /** @var array{ids: array<string, string>, translated: string|array<int, string>|null} $defaults */
        $defaults = [
            'ids' => [],
            'translated' => null,
        ];

        $messages = [];
        $item = $defaults;

        try {
            while (($line = fgets($stream)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    // Whitespace indicated current item is done
                    $this->addMessage($messages, $item);
                    $item = $defaults;
                } elseif (substr($line, 0, 7) === 'msgid "') {
                    // We start a new msg so save previous
                    // TODO: Must be refactored in Step 3.4.6 (Translation System Modernization) — this
                    // hand-rolled PO parser ignores `msgctxt` contexts (same msgid in different contexts
                    // collide) and has limited comment support (see class docblock). Replace this fork
                    // with Symfony\Component\Translation\Loader\PoFileLoader once the transChoice→ICU
                    // migration removes the need for Pagekit's `|`/`{N}` plural format.
                    $this->addMessage($messages, $item);
                    $item = $defaults;
                    $item['ids']['singular'] = substr($line, 7, -1);
                } elseif (substr($line, 0, 8) === 'msgstr "') {
                    $item['translated'] = substr($line, 8, -1);
                } elseif ($line[0] === '"') {
                    if (is_array($item['translated']) || ($item['translated'] === null && $item['ids'] === [])) {
                        continue;
                    }

                    $fragment = substr($line, 1, -1);
                    if ($item['translated'] !== null) {
                        $item['translated'] .= $fragment;
                    } else {
                        $lastIdKey = array_key_last($item['ids']);
                        if ($lastIdKey !== null) {
                            $item['ids'][$lastIdKey] .= $fragment;
                        }
                    }
                } elseif (substr($line, 0, 14) === 'msgid_plural "') {
                    $item['ids']['plural'] = substr($line, 14, -1);
                } elseif (substr($line, 0, 7) === 'msgstr[') {
                    $size = strpos($line, ']');
                    if ($size === false) {
                        continue;
                    }
                    if (!is_array($item['translated'])) {
                        $item['translated'] = [];
                    }
                    $item['translated'][(int) substr($line, 7, 1)] = substr($line, $size + 3, -1);
                }

            }
            // save last item
            $this->addMessage($messages, $item);
        } finally {
            fclose($stream);
        }

        // filter empty translations
        return array_filter($messages);
    }

    /**
     * Save a translation item to the messages.
     *
     * A .po file could contain by error missing plural indexes. We need to
     * fix these before saving them.
     *
     * @param array<int|string, string>                                                       $messages
     * @param array{ids: array<string, string>, translated: string|array<int, string>|null}   $item
     */
    protected function addMessage(array &$messages, array $item): void
    {
        $singular = $item['ids']['singular'] ?? '';

        if (is_array($item['translated'])) {
            if ($singular === '') {
                return;
            }
            $messages[$singular] = stripslashes($item['translated'][0] ?? '');
            if (isset($item['ids']['plural'])) {
                $plurals = $item['translated'];
                // PO are by definition indexed so sort by index.
                ksort($plurals);
                // Make sure every index is filled.
                $count = array_key_last($plurals);
                if ($count === null) {
                    return;
                }
                // Fill missing spots with '-'.
                $empties = array_fill(0, $count + 1, '-');
                $plurals += $empties;
                ksort($plurals);
                $messages[$item['ids']['plural']] = stripcslashes(implode('|', $plurals));
            }
        } elseif ($singular !== '' && $item['translated'] !== null) {
            $messages[$singular] = stripslashes($item['translated']);
        }
    }
}
