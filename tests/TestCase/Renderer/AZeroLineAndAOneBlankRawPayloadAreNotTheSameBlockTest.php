<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 2 `raw_block`: zero payload lines contribute nothing, one blank payload
 * line contributes one newline, and an implementation MUST NOT encode those two
 * source shapes identically (markup-carve/carve-php#2714).
 *
 * The collapse here was not the oracle's - that one built the payload with a
 * `join`, which maps n lines to n-1 newlines. This parser already gave the two
 * shapes distinct payloads; the renderer lost the difference twice over. It
 * suppressed the block's terminating newline whenever the payload was nothing
 * but newlines, and a container strips its children's trailing newlines and
 * re-adds one, which cannot tell an empty last line from no line at all.
 *
 * The nine cases are the shapes markup-carve/carve corpus category 521 pins,
 * which the spec pin does not yet carry. Each keeps a paragraph above the block
 * so the difference is interior and a trimming comparison cannot hide it.
 */
class AZeroLineAndAOneBlankRawPayloadAreNotTheSameBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function corpusShapes(): array
    {
        return [
            'list item, zero lines' => [
                "- a\n\n  ```=html\n  ```\n\n- s\n",
                "<ul>\n  <li><p>a</p>\n    \n  </li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
            'list item, one blank line' => [
                "- a\n\n  ```=html\n\n  ```\n\n- s\n",
                "<ul>\n  <li><p>a</p>\n    \n\n  </li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
            'block quote, zero lines' => [
                "> a\n>\n> ```=html\n> ```\n\nb\n",
                "<blockquote>\n  <p>a</p>\n  \n</blockquote>\n<p>b</p>\n",
            ],
            'block quote, one blank line' => [
                "> a\n>\n> ```=html\n>\n> ```\n\nb\n",
                "<blockquote>\n  <p>a</p>\n  \n\n</blockquote>\n<p>b</p>\n",
            ],
            'admonition, zero lines' => [
                "::: note\na\n\n```=html\n```\n:::\n",
                "<aside class=\"admonition note\" aria-label=\"Note\">\n  <p>a</p>\n  \n</aside>\n",
            ],
            'admonition, one blank line' => [
                "::: note\na\n\n```=html\n\n```\n:::\n",
                "<aside class=\"admonition note\" aria-label=\"Note\">\n  <p>a</p>\n  \n\n</aside>\n",
            ],
            'unterminated fence, zero lines' => [
                "> a\n>\n> ```=html\n\nb\n",
                "<blockquote>\n  <p>a</p>\n  \n</blockquote>\n<p>b</p>\n",
            ],
            'unterminated fence, one blank line' => [
                "> a\n>\n> ```=html\n>\n\nb\n",
                "<blockquote>\n  <p>a</p>\n  \n\n</blockquote>\n<p>b</p>\n",
            ],
            'top level, one blank line' => [
                "a\n\n```=html\n\n```\n\nb\n",
                "<p>a</p>\n\n\n<p>b</p>\n",
            ],
        ];
    }

    #[DataProvider('corpusShapes')]
    public function testCorpusShapeRendersByteIdentically(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function payloadShapes(): array
    {
        return [
            'none' => ["```=html\n```\n", ''],
            'one empty' => ["```=html\n\n```\n", "\n"],
            'two empty' => ["```=html\n\n\n```\n", "\n\n"],
            // A whitespace-only line is verbatim CONTENT rather than a blank.
            'one space' => ["```=html\n \n```\n", ' '],
            'text then empty' => ["```=html\nb\n\n```\n", "b\n"],
        ];
    }

    /**
     * The payload the parser hands the renderer, which was never the collapsed
     * half of this bug.
     */
    #[DataProvider('payloadShapes')]
    public function testPayloadCarriesOneNewlinePerLine(string $source, string $payload): void
    {
        $html = (new CarveConverter())->convert("a\n\n" . $source . "\nb\n");

        $this->assertSame("<p>a</p>\n" . $payload . "\n<p>b</p>\n", $html);
    }

    public function testTheTwoShapesDoNotEncodeIdentically(): void
    {
        $converter = new CarveConverter();

        $this->assertNotSame(
            $converter->convert("a\n\n```=html\n```\n\nb\n"),
            $converter->convert("a\n\n```=html\n\n```\n\nb\n"),
        );
    }
}
