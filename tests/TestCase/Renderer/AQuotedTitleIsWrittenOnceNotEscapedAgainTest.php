<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence opener's quoted title is written VERBATIM, because the slot already
 * holds the one encoding it has.
 *
 * `quoted_title = '"', {character - '"'}, '"'` (PART 9 §12) states no escape
 * mechanism, so the value the writer receives is source: raw text for a code
 * fence, inline source for a div. Encoding it again doubled every backslash on
 * every pass without bound, and changed the div's rendered title from a
 * non-breaking space to a literal backslash (carve-php#2397).
 *
 * The assertions are the two `fmt` invariants rather than one pass's bytes,
 * because the FIRST pass looked right: `"a \ b"` came back `"a \\ b"`, which is
 * a plausible spelling of a different title.
 */
class AQuotedTitleIsWrittenOnceNotEscapedAgainTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function backslashTitleProvider(): array
    {
        return [
            'code fence, where the title is raw text' => ["```php \"a \\ b\"\nx\n```\n"],
            'colon fence, where the title is inline source' => ["::: note \"a \\ b\"\nx\n:::\n"],
            'directive' => ["::: toc \"a \\ b\"\n\n:::\n"],
            'a title that is only a backslash' => ["```php \"\\\\\"\nx\n```\n"],
            'two backslashes' => ["::: note \"a \\\\ b\"\nx\n:::\n"],
        ];
    }

    /**
     * `fmt(fmt(x)) == fmt(x)`. Before the fix each pass added one backslash, so
     * the second pass differed from the first and the third from the second.
     */
    #[DataProvider('backslashTitleProvider')]
    public function testASecondWriterPassChangesNothing(string $source): void
    {
        $first = CarveConverter::toCarve($source);
        $second = CarveConverter::toCarve($first);

        $this->assertSame($first, $second);
        $this->assertSame($second, CarveConverter::toCarve($second));
    }

    /**
     * The stronger half: the authored bytes come back unchanged, so the writer
     * adds nothing at all rather than settling on a doubled form.
     */
    #[DataProvider('backslashTitleProvider')]
    public function testTheAuthoredTitleComesBackByteIdentically(string $source): void
    {
        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    /**
     * No authored content lost. The div case broke this one: the title held an
     * escaped space, which renders as a non-breaking space, and the written form
     * rendered a literal backslash instead.
     */
    #[DataProvider('backslashTitleProvider')]
    public function testTheRenderedTitleSurvivesTheWriter(string $source): void
    {
        $converter = new CarveConverter();

        $this->assertSame(
            $converter->convert($source),
            $converter->convert(CarveConverter::toCarve($source)),
        );
    }

    public function testTheDivSTitleStillRendersItsNonBreakingSpace(): void
    {
        $written = CarveConverter::toCarve("::: note \"a \\ b\"\nx\n:::\n");

        $this->assertStringContainsString(
            'a &nbsp;b',
            (new CarveConverter())->convert($written),
        );
    }

    /**
     * carve-php#2375 lands in the same helper and taught it to REMOVE a
     * backslash: a `"` has no spelling, so it is dropped together with the
     * backslash that escapes it. This asks the helper to stop ADDING one, so the
     * removal is the control.
     */
    public function testAnUnspellableQuoteStillDropsWithItsEscapingBackslash(): void
    {
        $quote = chr(34);
        $backslash = chr(92);
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'admonition',
                    'kind' => 'note',
                    'title' => [['type' => 'text', 'value' => 'a ' . $backslash . $quote . ' b']],
                    'children' => [
                        ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]],
                    ],
                ],
            ],
        ]);

        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();
        $written = $writer->render($document);
        $report = $writer->finishConversionDiagnosticCollection();

        $this->assertSame("::: note \"a \\\\ b\"\nx\n:::\n", $written);
        $this->assertSame(1, $report['totalDiagnostics']);
        $this->assertSame('field-unspellable', $report['diagnostics'][0]['code']);
        $this->assertSame('title', $report['diagnostics'][0]['field']);
        $this->assertSame($written, CarveConverter::toCarve($written));
        $this->assertStringContainsString(
            'a \\ b',
            (new CarveConverter())->convert($written),
        );
    }
}
