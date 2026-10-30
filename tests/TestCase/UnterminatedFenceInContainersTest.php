<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An unterminated ``` or ~~~ opener interrupts an open paragraph in every
 * container, as at the top level, and runs to its container's end
 * (markup-carve/carve#4032).
 */
class UnterminatedFenceInContainersTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $html));
    }

    public function testTopLevelIsUnchanged(): void
    {
        // Corpus 81-paragraph-interruption-18.
        $this->assertSame('<p>a</p> <pre><code></code></pre>', $this->squash($this->converter->convert("a\n```\n")));
    }

    public function testInAListItem(): void
    {
        $this->assertSame(
            '<ul> <li>a</li> </ul> <pre><code></code></pre>',
            $this->squash($this->converter->convert("* a\n```\n")),
        );
    }

    public function testInABlockquote(): void
    {
        $this->assertSame(
            '<blockquote><p>a</p></blockquote> <pre><code></code></pre>',
            $this->squash($this->converter->convert("> a\n```\n")),
        );
    }

    public function testInAnAdmonitionBody(): void
    {
        // The host's `:::` closer inside the unclosed fence is payload.
        $html = $this->squash($this->converter->convert("::: note\na\n```\n:::\n"));

        $this->assertSame('<aside class="admonition note" aria-label="Note"> <p>a</p> <pre><code>::: </code></pre> </aside>', $html);
        $this->assertStringNotContainsString('<div>', $html);
    }

    public function testAClosedFenceStillInterrupts(): void
    {
        $html = $this->squash($this->converter->convert("* a\n```\nx\n```\n"));

        $this->assertStringContainsString('<pre><code>x', $html);
        $this->assertStringContainsString('</ul>', $html);
    }

    public function testAClosedFenceInsideABlockquoteStillOpens(): void
    {
        $html = $this->squash($this->converter->convert("> ```\n> x\n> ```\n"));

        $this->assertStringContainsString('<pre><code>x', $html);
    }
}
