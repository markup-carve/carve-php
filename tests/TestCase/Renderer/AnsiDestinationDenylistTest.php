<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ANSI target blanks a destination PART 9 §25 denies.
 *
 * §25 binds "EVERY TARGET THAT EMITS A RESOLVABLE URL, not only to the HTML
 * renderer", and gives the reason: a scheme blanked in one target and passed
 * through in another is not blocked, it is deferred by one step. This writer
 * printed the destination verbatim in its parenthetical - `a
 * (javascript:alert(1))` - where the Markdown writer already emitted `[a]()`.
 * Every current terminal emulator autolinks a URL in its output and hands it to
 * the OS handler on click, which is that one step (carve-php#867, carve#765).
 *
 * All three engines agreed, so it was a design rather than a defect in one of
 * them - the same shape as the Markdown bypass carve#385 fixed, where three
 * engines agreeing did not make it right.
 *
 * BLANKED, NOT OMITTED. §25 says to emit an EMPTY value, and the empty
 * parenthetical distinguishes "withheld" from "the author wrote none".
 * `$showTarget` is decided from the AUTHORED destination, so blanking cannot
 * change WHETHER the parenthetical appears - only what is in it. In carve-js and
 * carve-rs that separation had to be introduced; here the condition already read
 * the authored value and already excluded autolinks.
 *
 * THE LINK TEXT IS UNTOUCHED, here as in every target. A denied autolink has the
 * URL as its text, so it still shows those characters - HTML shows them inside
 * `href=""` too. Blanking there would edit the author's words rather than a
 * destination.
 *
 * NO NEW COPY of the denylist: this calls `HtmlRenderer::blankDangerousScheme()`,
 * the one implementation the Markdown writer already delegates to for the same
 * reason.
 */
class AnsiDestinationDenylistTest extends TestCase
{
    protected function ansi(string $source): string
    {
        $converter = CarveConverter::create(renderer: new AnsiRenderer(useColors: false));

        return trim((string)preg_replace('/\033\[[0-9;]*m/', '', $converter->convert($source)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function deniedSchemeProvider(): array
    {
        return [
            'javascript' => ['[a](javascript:alert(1))'],
            'vbscript' => ['[a](vbscript:x)'],
            'data' => ['[a](data:text/html,x)'],
            'file' => ['[a](file:///etc/passwd)'],
            'ms-msdt (OS handler)' => ['[a](ms-msdt:x)'],
            'search-ms (OS handler)' => ['[a](search-ms:x)'],
            'uppercase scheme' => ['[a](JAVASCRIPT:alert(1))'],
        ];
    }

    #[DataProvider('deniedSchemeProvider')]
    public function testADeniedDestinationIsBlanked(string $source): void
    {
        $this->assertSame('a ()', $this->ansi($source . "\n"));
    }

    public function testObfuscatingWhitespaceIsStrippedBeforeTheSchemeIsRead(): void
    {
        // The shape corpus 121 pins for HTML. An inline `(...)` destination cannot
        // begin with whitespace at all, so the probe never mattered there - a
        // reference DEFINITION can, and that path reaches this target.
        $source = "[click][a]\n\n[a]: \u{202f}javascript:alert(1)\n";

        $this->assertSame('click ()', $this->ansi($source));
    }

    public function testAnOrdinaryDestinationIsUntouched(): void
    {
        // The boundary that matters most: this must not blank what a terminal
        // reader actually wants to see.
        $this->assertSame('a (https://ok.test)', $this->ansi("[a](https://ok.test)\n"));
        $this->assertSame('a (/local/path)', $this->ansi("[a](/local/path)\n"));
        $this->assertSame('a (mailto:x@y.test)', $this->ansi("[a](mailto:x@y.test)\n"));
    }

    public function testAFragmentStillShowsNoParenthetical(): void
    {
        // Unchanged, and pinned because the fix touches the same branch.
        $this->assertSame('c', $this->ansi("[c](#frag)\n"));
    }

    public function testADeniedAutolinkGainsNoEmptyParenthetical(): void
    {
        // An autolink's text IS its destination, so no parenthetical was ever
        // shown. In the other two engines, deciding from the sanitized value
        // instead of the authored one produced `javascript:alert(1) ()`.
        $this->assertSame('javascript:alert(1)', $this->ansi("<javascript:alert(1)>\n"));
    }

    public function testAnImageIsUnaffected(): void
    {
        // It never printed a destination.
        $this->assertSame('[img: i]', $this->ansi("![i](ms-msdt:x)\n"));
    }

    public function testNoTargetPassesTheSchemeThrough(): void
    {
        // The property §25 is actually about, asserted across the targets rather
        // than only on this one.
        $source = "[a](javascript:alert(1))\n";
        $targets = [
            'html' => (new CarveConverter())->convert($source),
            'markdown' => CarveConverter::markdown()->convert($source),
            'plain' => CarveConverter::plainText()->convert($source),
            'ansi' => $this->ansi($source),
        ];

        foreach ($targets as $name => $out) {
            $this->assertStringNotContainsString('javascript:', $out, $name . ' passed the scheme through');
        }
    }
}
