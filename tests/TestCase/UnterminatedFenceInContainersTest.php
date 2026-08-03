<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §10's I4: an unterminated ``` or ~~~ opener does not interrupt. The
 * line stays paragraph text and a stray ``` opens an unclosed inline verbatim
 * run.
 *
 * This engine applied that at the top level only. `hasClosingFenceAhead`
 * returns TRUE when the surrounding lines are not supplied, so every caller
 * that passes just the line - which is most of the container paths - got
 * "interrupt" regardless of whether a closer exists (carve-php#642).
 *
 * carve-rs is correct in all four contexts; carve-js was correct in three and
 * is fixed for the fourth in carve-js#541.
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
        // Corpus 81-paragraph-interruption-18, already correct.
        $this->assertSame('<p>a <code></code></p>', $this->squash($this->converter->convert("a\n```\n")));
    }

    public function testInAListItem(): void
    {
        $this->assertSame(
            '<ul> <li>a <code></code></li> </ul>',
            $this->squash($this->converter->convert("* a\n```\n")),
        );
    }

    public function testInABlockquote(): void
    {
        $this->assertSame(
            '<blockquote><p>a <code></code></p></blockquote>',
            $this->squash($this->converter->convert("> a\n```\n")),
        );
    }

    public function testInAnAdmonitionBody(): void
    {
        // The spurious trailing <div> is the sharp end: the `:::` that closes
        // the admonition was being read a second time as an opener.
        $html = $this->squash($this->converter->convert("::: note\na\n```\n:::\n"));

        $this->assertSame('<aside class="admonition note"> <p>a <code></code></p> </aside>', $html);
        $this->assertStringNotContainsString('<div>', $html);
    }

    public function testAClosedFenceStillInterrupts(): void
    {
        // The guard is about the closer, not about containers.
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
