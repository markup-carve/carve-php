<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Consecutive definition-list entries are ONE list, blank line or not.
 *
 * `definition_list = definition_entry+` (markup-carve/carve#839). The grammar
 * mentions a blank line only as a separator an author MAY write "for
 * readability" - never as one the list requires - so two entries written back
 * to back are the same list.
 *
 * This engine ended the list at the first entry unless a blank line followed,
 * and the block stream then opened a second `<dl>` for the next entry, where
 * carve-js and carve-rs build one.
 *
 * It is not only a rendering difference: the canonical writer does not re-emit
 * the blank line between entries - all three engines join them with a single
 * newline - so `to_html(fmt(x)) == to_html(x)` (PART 11 §1) failed here on a
 * shape the writer produces itself.
 */
class ConsecutiveDefinitionEntriesTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testTwoEntriesWithNoBlankLineAreOneList(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t1</dt>\n  <dd>d1</dd>\n  <dt>t2</dt>\n  <dd>d2</dd>\n</dl>\n",
            $this->html(":: t1\n:  d1\n:: t2\n:  d2\n"),
        );
    }

    public function testThreeEntriesWithNoBlankLinesAreOneList(): void
    {
        $this->assertSame(1, substr_count($this->html(":: a\n:  1\n:: b\n:  2\n:: c\n:  3\n"), '<dl>'));
    }

    public function testABlankLineBetweenEntriesStillGivesOneList(): void
    {
        // The control: the shape that already worked must keep working, and
        // must agree with the one above.
        $this->assertSame(
            $this->html(":: t1\n:  d1\n:: t2\n:  d2\n"),
            $this->html(":: t1\n:  d1\n\n:: t2\n:  d2\n"),
        );
    }

    public function testTheDocumentRoundTripsThroughTheWriter(): void
    {
        // The reason this mattered: the writer joins entries with a single
        // newline, so it produces the very shape the parser was splitting.
        $source = ":: t1\n:  d1\n\n:: t2\n:  d2\n";
        $this->assertSame($this->html($source), $this->html(CarveConverter::toCarve($source)));
    }

    public function testABlankLineNotFollowedByAnEntryStillEndsTheList(): void
    {
        // The other control: a blank followed by ordinary prose ends the list,
        // which is the rule this fix must not have widened past.
        $html = $this->html(":: t\n:  d\n\ntext\n");
        $this->assertStringContainsString('<p>text</p>', $html);
        $this->assertSame(1, substr_count($html, '<dl>'));
    }
}
