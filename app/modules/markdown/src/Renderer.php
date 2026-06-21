<?php

declare(strict_types=1);

namespace Pagekit\Markdown;

class Renderer
{
    /** @var array<string, mixed> */
    protected array $options = [];

    /**
     * @param array<string, mixed> $options
     */
    public function init(array $options = []): void
    {
        $this->options = $options;
    }

    public function code(string $code, ?string $lang = null, ?bool $escaped = null): string
    {
        if ($this->options['highlight']) {

            $out = $this->options['highlight']($code, $lang);

            if ($out != null && $out !== $code) {
                $escaped = true;
                $code = $out;
            }
        }

        $class = $lang ? ' class="'.$this->options['langPrefix'].Markdown::escape($lang, true).'"' : '';
        $code = $escaped ? $code : Markdown::escape($code, true);

        return "<pre><code{$class}>{$code}\n</code></pre>\n";
    }

    public function blockquote(string $quote): string
    {
        return "<blockquote>\n{$quote}</blockquote>\n";
    }

    public function html(string $html): string
    {
        return $html;
    }

    public function heading(string $text, int $level, string $raw = ''): string
    {
        $id = $this->options['headerPrefix'].preg_replace('/[^\w]+/m', '-', strtolower($raw));

        return "<h{$level} id=\"{$id}\">{$text}</h{$level}>\n";
    }

    public function hr(): string
    {
        return $this->options['xhtml'] ? "<hr/>\n" : "<hr>\n";
    }

    public function lst(string $body, bool $ordered = false): string
    {
        return $ordered ? "<ol>\n{$body}</ol>\n" : "<ul>\n{$body}</ul>\n";
    }

    public function listitem(string $text): string
    {
        return "<li>{$text}</li>\n";
    }

    public function paragraph(string $text): string
    {
        return "<p>{$text}</p>\n";
    }

    public function table(string $header, string $body): string
    {
        return "<table>\n<thead>\n{$header}</thead>\n<tbody>\n{$body}</tbody>\n</table>\n";
    }

    public function tablerow(string $content): string
    {
        return "<tr>\n".$content."</tr>\n";
    }

    /**
     * @param array{header?: bool, align?: string|null} $flags
     */
    public function tablecell(string $content, array $flags = []): string
    {
        $type = ($flags['header'] ?? false) ? 'th' : 'td';
        $align = $flags['align'] ?? null;
        $tag = $align !== null
          ? '<'.$type.' style="text-align:'.$align.'">'
          : '<'.$type.'>';

        return $tag.$content."</".$type.">\n";
    }

    // span level renderer
    public function strong(string $text): string
    {
        return "<strong>{$text}</strong>";
    }

    public function em(string $text): string
    {
        return "<em>{$text}</em>";
    }

    public function codespan(string $text): string
    {
        return "<code>{$text}</code>";
    }

    public function br(): string
    {
        return $this->options['xhtml'] ? '<br/>' : '<br>';
    }

    public function del(string $text): string
    {
        return "<del>{$text}</del>";
    }

    public function link(string $href = '', string $title = '', string $text = ''): string
    {
        if ($this->options['sanitize'] && strpos($href, 'javascript:') === 0) {
            return '';
        }

        $title = $title ? " title=\"{$title}\"" : '';

        return "<a href=\"{$href}\"{$title}>{$text}</a>";
    }

    public function image(string $href = '', string $title = '', string $text = ''): string
    {
        $title = $title ? " title=\"{$title}\"" : '';
        $close = $this->options['xhtml'] ? '/>' : '>';

        return "<img src=\"{$href}\" alt=\"{$text}\"{$title}{$close}";
    }
}
