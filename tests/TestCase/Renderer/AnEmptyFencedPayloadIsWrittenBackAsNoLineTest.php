<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §1: `to_html(fmt(x)) == to_html(x)`. A fenced block whose payload is
 * empty was written back with one blank payload line, and this engine's reader
 * tells the two apart, so the written document rendered something the authored
 * one did not (carve-php#2727).
 *
 * The two halves read the same rule off two encodings. A CODE payload is literal
 * text, so a payload of no lines is `""` and one blank line is `"\n"`
 * (`CARVE-P12-064`). A RAW block keeps the joined encoding, where corpus
 * `521-a-zero-line-and-a-one-blank-raw-payload-are-not-the-same-block` is the
 * document that says the two are different blocks.
 *
 * EVERY ROW RUNS TWICE, on a fresh converter and on one that has already
 * rendered: `Performance\BorrowedHtmlLayout` writes HTML straight from the source
 * without building a tree, and its plan only applies while the converter is still
 * cold, so a grid measured through one shared converter reaches it on the first
 * document alone (carve-php#2721).
 *
 * Expectations measured against the oracle at carve `e778d33a`.
 */
class AnEmptyFencedPayloadIsWrittenBackAsNoLineTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function fencedPayloads(): array
    {
        return [
            // Corpus 524 and its sibling: the code-fence half.
            'code, no payload line' => ["```\n```\n", "```\n```\n"],
            'code, one blank payload line' => ["```\n\n```\n", "```\n\n```\n"],
            'code, two blank payload lines' => ["```\n\n\n```\n", "```\n\n\n```\n"],
            'code, a text line' => ["```\nq\n```\n", "```\nq\n```\n"],
            'code, a text line then a blank' => ["```\nq\n\n```\n", "```\nq\n\n```\n"],
            'code, no payload line with a language' => ["``` php\n```\n", "```php\n```\n"],
            'code, no payload line inside an item' => ["- a\n\n  ```\n  ```\n", "- a\n  ```\n  ```\n"],
            // An unclosed opener that collected no line has no payload at all.
            'code, unclosed opener' => ["```\n", "```\n```\n"],
            // Corpus 521 and its siblings: the raw-block half.
            'raw, no payload line' => ["```=html\n```\n", "```=html\n```\n"],
            'raw, one blank payload line' => ["```=html\n\n```\n", "```=html\n\n```\n"],
            'raw, two blank payload lines' => ["```=html\n\n\n```\n", "```=html\n\n\n```\n"],
            'raw, a text line' => ["```=html\n<b>q</b>\n```\n", "```=html\n<b>q</b>\n```\n"],
            'raw, no payload line inside a quote' => ["> a\n>\n> ```=html\n> ```\n", "> a\n>\n> ```=html\n> ```\n"],
            'raw, no payload line inside an item' => ["- a\n\n  ```=html\n  ```\n", "- a\n  ```=html\n  ```\n"],
            'raw, no payload line inside a div' => ["::: n\n```=html\n```\n:::\n", "::: n\n```=html\n```\n:::\n"],
        ];
    }

    #[DataProvider('fencedPayloads')]
    public function testTheWriterReproducesThePayloadShape(string $source, string $written): void
    {
        $this->assertSame($written, CarveConverter::carve()->convert($source));
    }

    #[DataProvider('fencedPayloads')]
    public function testAFreshConverterRendersTheWrittenDocumentTheSame(string $source, string $written): void
    {
        $authored = (new CarveConverter())->convert($source);

        $this->assertSame($authored, (new CarveConverter())->convert($written));
    }

    #[DataProvider('fencedPayloads')]
    public function testAWarmConverterRendersTheWrittenDocumentTheSame(string $source, string $written): void
    {
        $converter = new CarveConverter();
        $converter->convert("warm\n");
        $authored = $converter->convert($source);

        $this->assertSame($authored, $converter->convert($written));
    }

    /**
     * The control: the blank line this writer used to emit turns corpus 524 into
     * its own sibling, and the raw block's zero-line payload into the one-blank
     * payload corpus 521 says is a different block.
     *
     * @return void
     */
    public function testTheBlankLineTheWriterUsedToEmitChangesTheDocument(): void
    {
        $converter = new CarveConverter();

        $this->assertSame("<pre><code></code></pre>\n", $converter->convert("```\n```\n"));
        $this->assertSame("<pre><code>\n</code></pre>\n", $converter->convert("```\n\n```\n"));
        $this->assertSame("\n", $converter->convert("```=html\n```\n"));
        $this->assertSame("\n\n", $converter->convert("```=html\n\n```\n"));
    }
}
