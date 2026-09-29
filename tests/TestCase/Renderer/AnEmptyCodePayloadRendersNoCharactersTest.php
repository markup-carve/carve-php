<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `code_content` is any text until the matching fence, preserved literally, so zero
 * payload lines is zero characters. A newline is content, and emitting one publishes
 * a character the author did not write (markup-carve/carve#2560, corpus category 524).
 *
 * The newline the renderer owns ends the payload's LAST LINE, which the parser strips
 * off the stored content. A payload with no lines has no last line. An UNCLOSED fence
 * is not that shape: it runs to the end of its block and takes a line whatever follows
 * the opener, which the rows below pin alongside the one-blank-line spelling.
 *
 * EVERY ROW RUNS TWICE, on a fresh converter and on one that has already rendered.
 * `CarveConverter::convert()` has two spellings of the code fence: a borrowed layout
 * that writes HTML straight from the source, and the block renderer. The borrowed one
 * is only reached while the plan still applies, so a grid measured with one shared
 * converter exercised it on the first document alone and read every later one through
 * the other path (carve-php#2721).
 *
 * Expectations measured against the oracle at carve `e778d33a` over fifteen shapes.
 */
class AnEmptyCodePayloadRendersNoCharactersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function payloadShapes(): array
    {
        return [
            // Corpus 524's two documents.
            'no payload line' => ["```\n```\n", "<pre><code></code></pre>\n"],
            'one blank payload line' => ["```\n\n```\n", "<pre><code>\n</code></pre>\n"],
            'two blank payload lines' => ["```\n\n\n```\n", "<pre><code>\n\n</code></pre>\n"],
            // A whitespace-only line is verbatim content, not a blank.
            'one space' => ["```\n \n```\n", "<pre><code> \n</code></pre>\n"],
            'one text line' => ["```\nq\n```\n", "<pre><code>q\n</code></pre>\n"],
            'a text line then a blank' => ["```\nq\n\n```\n", "<pre><code>q\n\n</code></pre>\n"],
            'no payload line, with a language' => [
                "``` php\n```\n",
                "<pre><code class=\"language-php\"></code></pre>\n",
            ],
            'no payload line, tilde fence' => ["~~~\n~~~\n", "<pre><code></code></pre>\n"],
            'no payload line, closer ends the file' => ["```\n```", "<pre><code></code></pre>\n"],
            'no payload line, inside an item' => [
                "- a\n\n  ```\n  ```\n",
                "<ul>\n  <li>a\n    <pre><code></code></pre>\n  </li>\n</ul>\n",
            ],
            // An unclosed opener takes a line whatever follows it.
            'unclosed, nothing after the opener' => ["```\n", "<pre><code>\n</code></pre>\n"],
            'unclosed, not even a line break' => ['```', "<pre><code>\n</code></pre>\n"],
            'unclosed, one blank line' => ["```\n\n", "<pre><code>\n</code></pre>\n"],
            'unclosed, a text line' => ["```\nq\n", "<pre><code>q\n</code></pre>\n"],
            'unclosed, a text line and no break' => ["```\nq", "<pre><code>q\n</code></pre>\n"],
        ];
    }

    #[DataProvider('payloadShapes')]
    public function testAFreshConverterTakesTheBorrowedPath(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    #[DataProvider('payloadShapes')]
    public function testAWarmConverterTakesTheBlockRenderer(string $source, string $expected): void
    {
        $converter = new CarveConverter();
        $converter->convert("warm\n");

        $this->assertSame($expected, $converter->convert($source));
    }

    /**
     * The distinction does NOT survive the AST, and saying so here is cheaper than
     * rediscovering it. `code_block` carries one `content` string, the empty string
     * for both shapes, and carve-js serializes it the same way, so a tree that has
     * been through the codec renders the one-line reading. Publishing a second field
     * would invent one the reference has no counterpart for.
     *
     * @return void
     */
    public function testTheCodecCannotCarryTheDistinction(): void
    {
        $converter = new CarveConverter();
        $encoded = (new AstCodec())->encode($converter->parse("```\n```\n"));
        $decoded = (new AstCodec())->decode($encoded);

        $this->assertSame("<pre><code></code></pre>\n", $converter->convert("```\n```\n"));
        $this->assertSame("<pre><code>\n</code></pre>\n", $converter->render($decoded));
    }
}
