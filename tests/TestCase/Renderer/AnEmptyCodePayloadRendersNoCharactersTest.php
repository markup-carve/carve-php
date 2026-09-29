<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `code_block.content` is literal payload text, so zero payload lines is zero
 * characters and HTML adds no newline of its own (`CARVE-P12-064`, corpus category
 * 524). The break before a closing fence belongs to the last payload line, which is
 * why a closed fence holding `a` reads `"a\n"`; a fence that ends at EOF without a
 * closer keeps whether its last line had one, and an opener that collected no line
 * has no payload at all.
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
            // An unclosed opener collects the lines below it and no more.
            'unclosed, nothing after the opener' => ["```\n", "<pre><code></code></pre>\n"],
            'unclosed, not even a line break' => ['```', "<pre><code></code></pre>\n"],
            'unclosed, one blank line' => ["```\n\n", "<pre><code>\n</code></pre>\n"],
            'unclosed, a text line' => ["```\nq\n", "<pre><code>q\n</code></pre>\n"],
            'unclosed, a text line and no break' => ["```\nq", "<pre><code>q</code></pre>\n"],
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
     * THE BREAK RIDES IN `content`, so the codec carries it and both of this
     * engine's renderers answer alike about the same document. `""`, `"\n"`,
     * `"a"` and `"a\n"` are four different payloads and survive JSON as four
     * different values.
     *
     * @return array<string, array{string, string}>
     */
    public static function encodings(): array
    {
        return [
            'no payload line' => ["```\n```\n", ''],
            'one blank payload line' => ["```\n\n```\n", "\n"],
            'two blank payload lines' => ["```\n\n\n```\n", "\n\n"],
            'three blank payload lines' => ["```\n\n\n\n```\n", "\n\n\n"],
            'one text line' => ["```\na\n```\n", "a\n"],
            'a text line then a blank' => ["```\na\n\n```\n", "a\n\n"],
            'a blank then a text line' => ["```\n\na\n```\n", "\na\n"],
            'one whitespace-only line' => ["```\n \n```\n", " \n"],
            // An opener that collected no line has no payload, whatever ended it.
            'unclosed, nothing after the opener' => ["```\n", ''],
            'unclosed, not even a line break' => ['```', ''],
            'unclosed, a text line' => ["```\na\n", "a\n"],
            // Only a fence reaching EOF can keep an unterminated last line.
            'unclosed at EOF, a text line with no break' => ["```\na", 'a'],
            'unclosed at EOF inside a quote' => ["> ```\n> a", 'a'],
            'a fence whose body fell below the content column' => ["- ```\nx\n```\n", ''],
            'an opener a quote closed before any payload line' => ["> ```\n", ''],
        ];
    }

    #[DataProvider('encodings')]
    public function testContentCarriesThePayloadLineCount(string $source, string $content): void
    {
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse($source));

        $this->assertSame($content, $this->firstCodeBlockContent($encoded));
    }

    /**
     * The two renderers of one document answer alike, which is what #2726 asked
     * for. Asserted on a fresh converter and on a warm one for the reason the grid
     * above is: the borrowed layout cannot be reached from a tree, so the parse
     * path is the only one that has two spellings.
     */
    #[DataProvider('encodings')]
    public function testTheCodecRoundTripRendersTheSameDocument(string $source, string $content): void
    {
        $codec = new AstCodec();
        $encoded = $codec->encode((new CarveConverter())->parse($source));
        $fresh = (new CarveConverter())->convert($source);
        $warm = new CarveConverter();
        $warm->convert("warm\n");

        $this->assertSame($fresh, $warm->convert($source));
        $this->assertSame($fresh, (new CarveConverter())->render($codec->decode($encoded)));
        $this->assertSame($fresh, $warm->render($codec->decode($encoded)));
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
