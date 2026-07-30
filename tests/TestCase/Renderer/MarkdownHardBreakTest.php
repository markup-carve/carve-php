<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 section 9: a hard break is emitted as a BACKSLASH before the newline,
 * never as two trailing spaces.
 *
 * Both mean `<br />` to a CommonMark reader, verified against commonmark.js. The
 * difference is what survives handling: trailing whitespace is removed by editors
 * that strip on save, by `git apply --whitespace=fix` and by CI whitespace checks,
 * and losing ONE of the two spaces is enough for the break to VANISH rather than
 * degrade.
 *
 * A line block converts to hard breaks, so this was our own output carrying the
 * fragile spelling (carve#352, corpus 41-line-blocks).
 */
class MarkdownHardBreakTest extends TestCase
{
    private CarveConverter $converter;

    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->renderer = new MarkdownRenderer();
    }

    private function render(string $source): string
    {
        return $this->renderer->render($this->converter->parse($source));
    }

    public function testAnExplicitHardBreakIsABackslash(): void
    {
        $this->assertSame("a\\\nb\n", $this->render("a\\\nb\n"));
    }

    /**
     * The property that matters: the break cannot be destroyed by whitespace
     * handling. Stated this way the test keeps holding if the spelling is ever
     * revisited for another reason.
     */
    public function testNoLineEndsInWhitespace(): void
    {
        $out = $this->render("a\\\nb\n");

        foreach (explode("\n", $out) as $line) {
            $this->assertSame(rtrim($line, " \t"), $line, 'in ' . var_export($out, true));
        }
    }

    public function testALineBlockUsesIt(): void
    {
        $source = "::: |\nStanza one,\nstill one.\n\nStanza two.\n:::\n";

        $this->assertSame("Stanza one,\\\nstill one.\n\nStanza two.\n", $this->render($source));
    }

    public function testASoftBreakStaysAPlainNewline(): void
    {
        $this->assertSame("a\nb\n", $this->render("a\nb\n"));
    }
}
