<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Theme;

use PHPUnit\Framework\TestCase;

/**
 * Covers image(), the <img> builder the theme templates render logos with.
 *
 * It takes the source it is to render and escapes everything into attributes.
 * Turning a stored path into a URL is the caller's part - the templates have the
 * view and its url helper for that - so the function reaches for nothing beyond
 * its arguments and a call needs no wiring of any kind: the tests below call it
 * on a bare helper file.
 */
final class ThemeOneImageTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    /**
     * The source is rendered as it was handed in. The caller resolved it
     * already, so anything done to it here would be a second resolution
     * competing with the first.
     */
    public function testTheSourceIsRenderedAsItWasHandedIn(): void
    {
        $this->assertSame(
            '<img src="/pagekit/storage/logo.png" alt>',
            image('/pagekit/storage/logo.png')
        );
    }

    /**
     * An image without a caption still carries the attribute: a logo repeats
     * what the site title already says, so it is marked as decorative rather
     * than left for a screen reader to read the file name out of.
     */
    public function testAnImageWithoutAnAltTextStillCarriesTheAttribute(): void
    {
        $this->assertSame('<img src="/logo.png" alt>', image('/logo.png', ['alt' => '']));
    }

    /**
     * Attribute values are site settings, so they can carry the quote that
     * would otherwise end the attribute and start one of the author's own.
     * Text that is already encoded stays encoded once - it is markup on the
     * page, not the entity spelled out.
     */
    public function testAttributeValuesAreEscapedAndNotDoubleEncoded(): void
    {
        $this->assertSame(
            '<img src="/logo.png" alt="M&amp;S &quot;logo&quot; &amp; more">',
            image('/logo.png', ['alt' => 'M&amp;S "logo" & more'])
        );
    }

    /**
     * The source is built from a stored file path, and escaping it on the way
     * into the attribute is what keeps a crafted file name from closing the src
     * and adding attributes - an onerror handler among them - of its own.
     */
    public function testAQuoteInTheSourceCannotEndTheAttribute(): void
    {
        $this->assertSame(
            '<img src="/storage/logo.png&quot; onerror=&quot;alert(1)" alt>',
            image('/storage/logo.png" onerror="alert(1)')
        );
    }

    /**
     * How the templates ask for an svg logo: classes as a list, a flag that
     * renders as the bare attribute UIkit looks for, and the dimensions left
     * empty so the file's own are used. Empty values are dropped rather than
     * rendered as width="", which would collapse the logo.
     */
    public function testFlagsRenderBareAndEmptyValuesAreLeftOut(): void
    {
        $markup = image('/logo.svg', [
            'class' => ['', 'uk-preserve'],
            'alt' => 'Acme',
            'uk-svg' => true,
            'width' => '',
            'height' => '',
        ]);

        $this->assertSame('<img src="/logo.svg" class="uk-preserve" alt="Acme" uk-svg>', $markup);
    }
}
