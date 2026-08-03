<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unterminated verbatim fence does not interrupt, in any container.
 *
 * PART 9 §10's I4 guards it unconditionally: an unterminated ``` or ~~~ opener
 * does not interrupt, the line stays paragraph text, and the stray fence then
 * opens an unclosed inline verbatim run rendering as `<code>` to the end of the
 * block.
 *
 * carve-php applied that at the top level only. Inside a list item, a block
 * quote or an admonition body it broke out of the container, and in the
 * admonition case it also invented a node: the `:::` closing the container was
 * swallowed into the body by the unclosed fence, so the container ran to end of
 * file and the swallowed `:::` was read a second time as an opener, leaving a
 * phantom empty `<div>` behind (carve-php#642).
 *
 * Every expectation here is carve-rs's output, which was correct in all four
 * contexts.
 */
final class UnterminatedFenceInContainerTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unterminatedFenceProvider(): array
    {
        return [
            'top level' => [
                "a\n```\n",
                "<p>a\n<code></code></p>\n",
            ],
            'inside a list item' => [
                "* a\n```\n",
                "<ul>\n  <li>a\n<code></code></li>\n</ul>\n",
            ],
            'inside a block quote' => [
                "> a\n```\n",
                "<blockquote><p>a\n<code></code></p></blockquote>\n",
            ],
            'inside an admonition body' => [
                "::: note\na\n```\n:::\n",
                "<aside class=\"admonition note\">\n  <p>a\n<code></code></p>\n</aside>\n",
            ],
            // A tilde fence stays literal TEXT rather than opening a run:
            // only backticks open inline verbatim, so there is no `<code>` to
            // fall back to. carve-rs renders the same, at the top level and in
            // a quote alike.
            'a tilde fence stays literal text' => [
                "> a\n~~~\n",
                "<blockquote><p>a\n~~~</p></blockquote>\n",
            ],
        ];
    }

    #[DataProvider('unterminatedFenceProvider')]
    public function testAnUnterminatedFenceDoesNotBreakOutOfItsContainer(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }

    /**
     * The other half of the rule, so the guard cannot be satisfied by refusing
     * every fence: a fence that DOES close is a real block and still ends the
     * container's paragraph.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function terminatedFenceProvider(): array
    {
        return [
            'inside a list item' => [
                "* a\n```\nx\n```\n",
                "<ul>\n  <li>a</li>\n</ul>\n<pre><code>x\n</code></pre>\n",
            ],
            'inside an admonition body' => [
                "::: note\na\n```\nx\n```\n:::\n",
                "<aside class=\"admonition note\">\n  <p>a</p>\n  <pre><code>x\n</code></pre>\n</aside>\n",
            ],
        ];
    }

    #[DataProvider('terminatedFenceProvider')]
    public function testATerminatedFenceStillOpensABlock(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }
}
