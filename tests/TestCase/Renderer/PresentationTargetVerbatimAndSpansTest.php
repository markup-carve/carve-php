<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Two presentation-target divergences, both of them this engine alone.
 *
 * Neither is visible to a fixture carrying an HTML expectation, which is why
 * both survived: the corpus pins HTML, and for every other target the
 * engine-against-engine comparison IS the check.
 */
class PresentationTargetVerbatimAndSpansTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function ansi(string $source): string
    {
        return (new AnsiRenderer())->render($this->converter->parse($source));
    }

    private function plain(string $source): string
    {
        return (new PlainTextRenderer())->render($this->converter->parse($source));
    }

    /**
     * A code block's content is verbatim, and the ANSI target used to split it
     * on `rtrim()` - which drops the terminating newline but takes the last
     * line's trailing space with it.
     */
    public function testTheAnsiTargetKeepsATrailingSpaceOnACodeBlocksLastLine(): void
    {
        $ansi = $this->ansi("```\nabc \n```\n");

        $this->assertStringContainsString("  abc \x1b[0m", $ansi);
    }

    /**
     * The same `rtrim()` also took every blank line at the end of the block.
     * They are content: the author typed them inside a fence.
     */
    public function testTheAnsiTargetKeepsABlankLineAtTheEndOfACodeBlock(): void
    {
        $ansi = $this->ansi("```\nabc\n\n\n```\n\nafter\n");

        // `abc`, then the surviving blank content line, each styled.
        $this->assertSame(2, substr_count($ansi, "\x1b[97m  "));
    }

    /**
     * The HTML target keeps both, and always did - so the engine rendered one
     * code block two ways depending on the target it was asked for.
     */
    public function testTheHtmlTargetAgreedAllAlong(): void
    {
        $html = (new CarveConverter())->convert("```\nabc \n```\n");

        $this->assertStringContainsString("<pre><code>abc \n</code></pre>", $html);
    }

    /**
     * A `<` marker is a cell the writer typed, so the row reaches that column
     * and is not short. Trimming it back drew a row narrower than its border.
     */
    public function testASpannedLastColumnSurvivesInTheAnsiTarget(): void
    {
        $ansi = $this->ansi("| a | b |\n| c | < |\n");

        $lines = array_values(array_filter(explode("\n", $ansi), static fn (string $l): bool => $l !== ''));
        $widths = array_map(
            static fn (string $l): int => mb_strlen((string)preg_replace('/\x1b\[[0-9;]*m/', '', $l)),
            $lines,
        );

        $this->assertCount(1, array_unique($widths), 'every line of the box is the same width');
    }

    /**
     * The same row on the plain-text target, which draws no border and so lost
     * the column silently.
     */
    public function testASpannedLastColumnSurvivesInThePlainTarget(): void
    {
        $this->assertSame("a | b\nc |\n", $this->plain("| a | b |\n| c | < |\n"));
    }

    /**
     * Padding is still dropped: a row that genuinely does not reach the last
     * column gains no cell for it.
     */
    public function testAShortRowStillLosesItsPadding(): void
    {
        $this->assertSame("a | b\nc\n", $this->plain("| a | b |\n| c |\n"));
    }
}
