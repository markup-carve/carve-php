<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `fmt` writes a footnote body at TWO spaces, the body's own column.
 *
 * Three spaces is legal authored input and now establishes a local block base
 * under carve#1729. The writer still uses the minimum column for stable,
 * portable canonical output.
 */
class FootnoteBodyIsWrittenAtItsOwnColumnTest extends TestCase
{
    protected function fmt(string $source): string
    {
        return (new CarveRenderer())->render((new CarveConverter())->parse($source));
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodyShapes(): array
    {
        return [
            'table' => ["  | a |\n  | - |\n  | b |\n"],
            'code fence' => ["  ```\n  code\n  ```\n"],
            'block quote' => ["  > quoted\n"],
            'heading' => ["  # H\n"],
            'div' => ["  :::\n  body\n  :::\n"],
            'nested list' => ["  - one\n    - deep\n"],
            'definition list' => ["  :: term\n  :  def\n"],
            // Holds either way, kept so the set covers what authors actually
            // write and so a narrowed fix still has to keep it working.
            'bullet list' => ["  - one\n  - two\n"],
            'second paragraph' => ["  second para\n"],
        ];
    }

    protected function document(string $body): string
    {
        return "[^a]: intro\n\n" . $body . "\nsee[^a]\n";
    }

    #[DataProvider('bodyShapes')]
    public function testTheBodyRoundTripsThroughFmt(string $body): void
    {
        $source = $this->document($body);

        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
    }

    #[DataProvider('bodyShapes')]
    public function testTheBodyIsWrittenAtTwoSpaces(string $body): void
    {
        // The round trip is the claim; this pins the mechanism, so a future
        // change that made the PARSER lenient instead would not read as a fix.
        $out = $this->fmt($this->document($body));

        $this->assertMatchesRegularExpression('/\n  \S/', $out, 'no body line at two spaces');
        $this->assertDoesNotMatchRegularExpression('/\n   \S/', $out, 'a body line indented past its own column');
    }

    public function testAThreeSpaceBodyUsesItsAuthoredBase(): void
    {
        // WHY two rather than three, stated as a fact about the reader: at three,
        // the table opener is indented and does not open. If this ever starts
        // failing, the parse rule moved and the writer's column can be revisited.
        $html = $this->html("[^a]: intro\n\n   | a |\n   | - |\n   | b |\n\nsee[^a]\n");

        $this->assertStringContainsString('<table>', $html);
    }

    public function testTheTwoSpaceFormIsReadAsATable(): void
    {
        // The other half of the same boundary - otherwise the assertion above
        // would also pass if tables were broken outright.
        $html = $this->html("[^a]: intro\n\n  | a |\n  | - |\n  | b |\n\nsee[^a]\n");

        $this->assertStringContainsString('<table>', $html);
    }

    public function testAnInlineOnlyBodyIsUnchanged(): void
    {
        // No continuation lines, so nothing to indent. The shape most notes use.
        $source = "[^a]: just text\n\nsee[^a]\n";

        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
        $this->assertStringContainsString('[^a]: just text', $this->fmt($source));
    }

    public function testAWrappedInlineBodyStillContinues(): void
    {
        // A plain continuation line, which was never broken: it must keep being
        // part of the body rather than becoming a sibling paragraph.
        $html = $this->html($this->fmt("[^a]: one\n  two\n\nsee[^a]\n"));

        $this->assertStringContainsString('one two', preg_replace('/\s+/', ' ', $html) ?? '');
    }
}
