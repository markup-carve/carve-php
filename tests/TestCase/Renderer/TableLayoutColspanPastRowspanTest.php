<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * A column reserved by a rowspan from an earlier row is not available to a later
 * cell's colspan, so the span skips past it -- which is what the `<` marker does
 * when it scans left past a consumed cell.
 *
 * The colspan loop pushed its continuation fillers without consulting the active
 * rowspans, so a spanning cell wrote over the reserved column and the rowspan
 * filler was never emitted at all. The row came out one field narrower than the
 * header with the last cell shifted left (carve#352, corpus 105).
 *
 * HtmlRenderer does not use TableLayout, which is why the HTML output was correct
 * throughout and only the non-HTML targets diverged.
 */
class TableLayoutColspanPastRowspanTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "| p | q | r | s |\n|---|---|---|---|\n| a | b | c | d |\n| p | ^ | < | e |\n";

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testThePlainRowKeepsEveryColumn(): void
    {
        $plain = (new PlainTextRenderer())
            ->render($this->converter->parse(self::SOURCE));

        $this->assertSame("p | q | r | s\na | b | c | d\np |  |  | e\n", $plain);
    }

    public function testTheSpanRowHasAsManyFieldsAsTheHeader(): void
    {
        $plain = (new PlainTextRenderer())
            ->render($this->converter->parse(self::SOURCE));
        $lines = explode("\n", trim($plain));

        $this->assertCount(
            count(explode('|', $lines[0])),
            explode('|', $lines[2]),
            'span row lost a column: ' . var_export($plain, true),
        );
    }

    public function testTheMarkdownRowKeepsEveryColumn(): void
    {
        $markdown = (new MarkdownRenderer())
            ->render($this->converter->parse(self::SOURCE));

        $this->assertStringContainsString('| p |  |  | e |', $markdown);
    }

    /**
     * The HTML target was always right here, and stays right: it computes its own
     * layout rather than going through TableLayout.
     */
    public function testTheHtmlTargetIsUnchanged(): void
    {
        $html = $this->converter->convert(self::SOURCE);

        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringContainsString('rowspan="2"', $html);
    }

    /**
     * An ordinary table with no spans must be unaffected by the column bookkeeping
     * change.
     */
    public function testAPlainTableIsUnaffected(): void
    {
        $plain = (new PlainTextRenderer())
            ->render($this->converter->parse("| a | b |\n|---|---|\n| c | d |\n"));

        $this->assertSame("a | b\nc | d\n", $plain);
    }
}
