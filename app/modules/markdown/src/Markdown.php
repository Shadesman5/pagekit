<?php

declare(strict_types=1);

namespace Pagekit\Markdown;

use Pagekit\Markdown\Lexer\BlockLexer;

class Markdown
{
    protected ?BlockLexer $lexer = null;
    protected ?Parser $parser = null;

    /** @var array<string, mixed> */
    protected array $options;

    /** @var array<string, mixed> */
    protected static array $defaults = [
        'gfm' => true,
        'tables' => true,
        'breaks' => false,
        'pedantic' => false,
        'sanitize' => false,
        'smartLists' => false,
        'silent' => false,
        'highlight' => false,
        'langPrefix' => 'lang-',
        'smartypants' => false,
        'headerPrefix' => '',
        'xhtml' => false,
    ];

    /**
     * Constructor.
     *
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        if (!isset($options['renderer'])) {
            $options['renderer'] = new Renderer();
        }

        $this->options = array_merge(static::$defaults, $options);
    }

    /**
     * Parses the markdown syntax and returns HTML.
     *
     * @param array<string, mixed> $options
     */
    public function parse(string $text, array $options = []): string
    {
        $options = array_merge($this->options, $options);
        $options['renderer']->init($options);

        $this->lexer = new BlockLexer($options);
        $this->parser = new Parser($options);

        return $this->parser->parse($this->lexer->lex($text));
    }

    /**
     * Convert special characters to HTML entities.
     */
    public static function escape(string $text, bool $encode = false): string
    {
        $text = preg_replace(!$encode ? '/&(?!#?\w+;)/' : '/&/', '&amp;', $text) ?? '';

        return str_replace(['<', '>', '"', '\''], ['&lt;', '&gt;', '&quot;', '&#39;'], $text);
    }
}
