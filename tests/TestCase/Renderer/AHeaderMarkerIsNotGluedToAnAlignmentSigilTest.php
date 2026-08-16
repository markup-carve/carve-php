<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 section 1: `to_html(fmt(x)) == to_html(x)`, on a table header cell.
 *
 * The parser's alignment scan runs at the character right after `|` or `|=` and
 * consumes exactly one of `< > ~`. A prefixed cell is written TIGHT, so the
 * first character of the content is the character that scan reads: `| ~x~ |`
 * came out `|=~x~|`, which re-reads as CENTER alignment with the text `x~` -
 * the strikethrough gone and every cell in the column centered by a marker the
 * author never wrote (carve-php#1069 cause 5).
 *
 * One space between the marker and the content is the whole fix, and it is the
 * argument the lone span marker one branch above already carries (carve#710):
 * the scan fires only on a GLUED sigil, and the content is trimmed once the
 * prefix is consumed.
 */
class AHeaderMarkerIsNotGluedToAnAlignmentSigilTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    protected function fmt(string $source): string
    {
        return CarveConverter::toCarve($source);
    }

    protected function assertRoundTrips(string $source): void
    {
        $once = $this->fmt($source);
        $this->assertSame($this->html($source), $this->html($once), 'to_html(fmt(x)) != to_html(x)');
        $this->assertSame($once, $this->fmt($once), 'fmt is not idempotent');
    }

    public function testAHeaderCellOpeningWithTheCenterSigilKeepsItsStrikethrough(): void
    {
        $source = "| ~x~ |\n|---|\n| y |\n";
        $this->assertSame("|= ~x~|\n| y |\n", $this->fmt($source));
        $this->assertRoundTrips($source);
        $out = $this->html($this->fmt($source));
        $this->assertStringContainsString('<s>x</s>', $out);
        $this->assertStringNotContainsString('text-align', $out);
    }

    public function testAHeaderCellOpeningWithTheLeftSigilKeepsItsAnchor(): void
    {
        $source = "| <https://e.com> |\n|---|\n| y |\n";
        $this->assertSame("|= <https://e.com>|\n| y |\n", $this->fmt($source));
        $this->assertRoundTrips($source);
        $out = $this->html($this->fmt($source));
        $this->assertStringContainsString('<a href="https://e.com">', $out);
        $this->assertStringNotContainsString('text-align', $out);
    }

    /**
     * The right sigil does NOT reach the defect today, because the escape pass
     * writes `\>` - it also opens a blockquote. It is guarded anyway: that is a
     * different rule's decision, and a guard leaning on it would break the day
     * that rule narrowed. So this row asserts the escaped spelling holds, and
     * the sigil set below asserts all three are in the guard.
     */
    public function testTheRightSigilIsGuardedEvenThoughTheEscapePassReachesItFirst(): void
    {
        $source = "| \\>x |\n|---|\n| y |\n";
        $this->assertRoundTrips($source);
        $this->assertStringContainsString('\\>', $this->fmt($source));
        $this->assertArrayHasKey('>', BlockParser::TABLE_ALIGNMENT_MARKERS);
        $this->assertCount(3, BlockParser::TABLE_ALIGNMENT_MARKERS);
    }

    /**
     * CONTROL. A BODY cell carries no prefix, so it is padded and the scan never
     * reaches its content. Asserted in bytes, not only through the round trip.
     */
    public function testABodyCellIsUnaffected(): void
    {
        $this->assertSame("|=h|\n| ~x~ |\n", $this->fmt("| h |\n|---|\n| ~x~ |\n"));
        $this->assertRoundTrips("| h |\n|---|\n| ~x~ |\n");
    }

    /**
     * CONTROL. A cell carrying an ATTRIBUTE BLOCK spends the alignment scan on
     * the block: PART 9 §5 T10 binds it AFTER the marker run, so everything
     * past the closing brace is content and no separating hazard is left.
     *
     * The written shape changed with T10 and the property did not. The block
     * used to be spelled ahead of the `=`, where it made a header cell
     * unspellable, so the writer fell back to a delimiter row (`|{.k}~x~|` over
     * `|---|`). It now spells the header directly, and `~x~` is still
     * strikethrough on the way back.
     */
    public function testACellWithAnAttributeBlockIsUnaffected(): void
    {
        $this->assertSame("|={.k}~x~|\n| y |\n", $this->fmt("|{.k} ~x~ |\n|---|\n| y |\n"));
        $this->assertRoundTrips("|{.k} ~x~ |\n|---|\n| y |\n");
    }

    /**
     * CONTROL. A ROW ATTRIBUTE sits after the closing pipe and is a separate
     * writer; it does not share the hazard.
     */
    public function testARowAttributeIsUnaffected(): void
    {
        $this->assertSame("|= ~x~|{.r}\n| y |\n", $this->fmt("| ~x~ |{.r}\n|---|\n| y |\n"));
        $this->assertRoundTrips("| ~x~ |{.r}\n|---|\n| y |\n");
    }

    /**
     * CONTROL, and the reading this ticket invites that is wrong: the delimiter
     * row is still rewritten as `|=`. `| a |` round-trips through `|=a|` today,
     * so the rewrite is not the defect and no mutation of the separator moves
     * this row.
     */
    public function testTheDelimiterRowIsStillRewrittenAsAHeaderMarker(): void
    {
        $this->assertSame("|=a|\n| b |\n", $this->fmt("| a |\n|---|\n| b |\n"));
        $this->assertRoundTrips("| a |\n|---|\n| b |\n");
    }

    /**
     * A prefix that already ENDS in an alignment marker has spent the scan, so
     * a sigil opening the content is content and no separator is written.
     */
    public function testACellWhoseOwnAlignmentMarkerAlreadySpentTheScanStaysTight(): void
    {
        $source = "|=~ ~x~ |\n| y |\n";
        $this->assertSame("|=~~x~|\n| y |\n", $this->fmt($source));
        $this->assertRoundTrips($source);
    }
}
