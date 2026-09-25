<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `quoted_title = '"', {character - '"'}, '"'` (PART 9 §12) has no escape
 * mechanism, so a title holding a `"` has no source spelling. The writer spelled
 * it `\"`, which closed the title at the second quote and left the rest of the
 * opener to re-parse as a paragraph: the container, its title and its whole body
 * were gone (carve-php#2375). The character is dropped instead and the loss
 * reported, which is the answer HtmlToCarve already gives on the import side
 * (`HtmlToCarveTest::testAdmonitionTitleParagraphWithDoubleQuoteFallsBackToValidOpener`).
 *
 * A `"` is unreachable from a parse, so this is an ingest-only shape. It is not
 * per kind: a directive, an admonition and a code fence all write the one slot.
 */
class AQuotedTitleHoldingADoubleQuoteDropsTheQuoteTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'directive',
                    'kind' => 'toc',
                    'title' => [['type' => 'text', 'value' => 'say "hi" now']],
                ],
                [
                    'type' => 'admonition',
                    'kind' => 'note',
                    'title' => [['type' => 'text', 'value' => 'say "hi" now']],
                    'children' => [
                        ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]],
                    ],
                ],
                [
                    'type' => 'code_block',
                    'content' => 'x',
                    'lang' => 'php',
                    'header' => 'say "hi" now',
                ],
            ],
        ];
    }

    public function testTheWrittenOpenerKeepsItsContainerAndItsTitle(): void
    {
        $document = (new AstCodec())->decode(self::payload());

        self::assertSame(
            "::: toc \"say hi now\"\n\n:::\n\n::: note \"say hi now\"\nx\n:::\n\n```php \"say hi now\"\nx\n```\n",
            CarveConverter::carve()->render($document),
        );
    }

    /**
     * The property the escape broke: the bytes the writer emits re-parse to the
     * document they were written from. Before the fix the source re-parsed to a
     * paragraph and a bare `<div>`, and the note's body folded into it.
     */
    public function testTheWrittenSourceReParsesToTheSameDocument(): void
    {
        $written = CarveConverter::carve()->render((new AstCodec())->decode(self::payload()));
        $reparsed = CarveConverter::carve()->parse($written);

        self::assertSame($written, CarveConverter::carve()->render($reparsed));
        self::assertSame(
            "<div class=\"toc\">\n  <p class=\"admonition-title\">say hi now</p>\n</div>\n"
                . "<aside class=\"admonition note\" aria-labelledby=\"adm-1\">\n"
                . "  <p class=\"admonition-title\" id=\"adm-1\">say hi now</p>\n  <p>x</p>\n</aside>\n"
                . "<pre title=\"say hi now\"><code class=\"language-php\">x\n</code></pre>\n",
            (new CarveConverter())->convert($written),
        );
    }

    public function testTheDroppedCharacterIsReported(): void
    {
        $document = (new AstCodec())->decode(self::payload());
        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();
        $writer->render($document);
        $report = $writer->finishConversionDiagnosticCollection();

        self::assertSame(3, $report['totalDiagnostics']);
        foreach ($report['diagnostics'] as $diagnostic) {
            self::assertSame('field-unspellable', $diagnostic['code']);
            self::assertSame('title', $diagnostic['field']);
        }
        self::assertSame(['div', 'div', 'code_block'], array_column($report['diagnostics'], 'node'));
    }

    /**
     * A title that is ONLY `"` still writes a valid opener. A bare strip would
     * leave the empty quoted title `""`, so the question is whether that parses
     * at all - it does, and carries an empty title.
     */
    public function testATitleThatIsOnlyAQuoteWritesAnEmptyTitle(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'directive', 'kind' => 'toc', 'title' => [['type' => 'text', 'value' => '"']]],
            ],
        ]);
        $written = CarveConverter::carve()->render($document);

        self::assertSame("::: toc \"\"\n\n:::\n", $written);
        self::assertSame(
            "<div class=\"toc\">\n  <p class=\"admonition-title\"></p>\n</div>\n",
            (new CarveConverter())->convert($written),
        );
    }

    /**
     * The other direction: a title with no `"` is written back byte for byte,
     * and nothing is reported.
     */
    #[DataProvider('untouchedProvider')]
    public function testATitleWithoutAQuoteRoundTripsByteIdentically(string $src): void
    {
        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();

        self::assertSame($src, $writer->render(CarveConverter::carve()->parse($src)));
        self::assertSame(0, $writer->finishConversionDiagnosticCollection()['totalDiagnostics']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function untouchedProvider(): array
    {
        return [
            'directive' => ["::: toc \"say hi now\"\n\n:::\n"],
            'admonition' => ["::: note \"Plain title\"\nx\n:::\n"],
            'admonition with a label' => ["::: note \"Plain title\" [End]\nx\n:::\n"],
            'code fence' => ["```php \"a title\"\ny\n```\n"],
        ];
    }
}
