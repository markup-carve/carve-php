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

    /**
     * WHY THE READER KEEPS THE ONE-LINE READING, measured rather than argued
     * (carve-php#2726).
     *
     * A reader holding `content: ""` renders a newline, which is wrong for corpus
     * 524 and right for every other document that serializes the empty string: a
     * fence whose body fell below its content column, and an opener a container
     * closed before any payload line arrived. Over the pinned corpus plus the four
     * documents carve `e778d33a` adds, reading the empty string as ZERO payload
     * lines turns ONE `php->php` ingest mismatch into TEN. The two rows below are
     * two of the nine it would break.
     *
     * So this is not a reader that can be corrected in place. The empty string has
     * to stop denoting both shapes, which is a change to `code_block` in the
     * upstream `resources/ast-schema.json` and to all three engines, not to this
     * one.
     *
     * @return void
     */
    public function testTheEmptyContentStringAlsoMeansAOneLinePayload(): void
    {
        $codec = new AstCodec();
        $converter = new CarveConverter();
        $shapes = [
            // Corpus 276: the fence body sits below the item's content column, so
            // the fence takes no payload line and the body is a paragraph below.
            "- ```\nx\n```\n" => "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n"
                . "<p>x\n<code></code></p>\n",
            // Corpus 69: the quote ends the opener before a payload line arrives.
            "> ```\n" => "<blockquote>\n  <pre><code>\n</code></pre>\n</blockquote>\n",
        ];

        foreach ($shapes as $source => $expected) {
            $encoded = $codec->encode($converter->parse($source));
            $this->assertSame('', $this->firstCodeBlockContent($encoded), $source);
            // Both paths already agree here, and they agree on the newline.
            $this->assertSame($expected, $converter->convert($source), $source);
            $this->assertSame($expected, $converter->render($codec->decode($encoded)), $source);
        }
    }

    /**
     * @param array<string, mixed> $encoded
     */
    protected function firstCodeBlockContent(array $encoded): ?string
    {
        if (($encoded['type'] ?? null) === 'code_block') {
            $content = $encoded['content'] ?? null;

            return is_string($content) ? $content : null;
        }
        /** @var array<array<string, mixed>> $children */
        $children = array_merge(
            is_array($encoded['children'] ?? null) ? $encoded['children'] : [],
            is_array($encoded['items'] ?? null) ? $encoded['items'] : [],
        );
        foreach ($children as $child) {
            $found = $this->firstCodeBlockContent($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
