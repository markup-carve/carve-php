<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A heading's separator run is SPACES, so the writer may not eat a tab.
 *
 * PART 2's MARKER SEPARATORS AND PADDING SLOTS gives the heading marker
 * `space+`, and `space` is U+0020 alone: the run ends at the first character
 * that is not one, and that character BEGINS the heading. The reader here
 * already agreed - `##<SP><TAB>x` parses as the heading `<TAB>x` and renders
 * `<h2><TAB>x</h2>`, which is what corpus `406-...-3` pins.
 *
 * The WRITER did not. It trimmed the rendered inline text with the same
 * whitespace set the document boundary uses, so the tab went with the
 * separator and `carve fmt` emitted `##<SP>x` - a character its own parser had
 * kept, and PART 11 section 1's first invariant, `parse(fmt(x)) == parse(x)`,
 * broken on a document the corpus pins. markup-carve/carve#1587 states the
 * rule; the pinned JS writer has the same defect, tracked as
 * markup-carve/carve-js#1356.
 *
 * The tab is representable because writing it back changes nothing: the run
 * after the marker still stops at it. A leading SPACE is NOT, so it stays
 * trimmed - any space the writer emitted would be re-consumed as separator.
 */
class AHeadingSLeadingTabSurvivesTheWriterTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function headings(): array
    {
        return [
            'a leading tab is the heading first character' => ["## \tx\n", "## \tx\n"],
            'the tab survives a longer separator run' => ["##   \tx\n", "## \tx\n"],
            'a tab run is kept whole' => ["# \t\tx\n", "# \t\tx\n"],
            'an interior tab was never at risk' => ["## \tx\ty\n", "## \tx\ty\n"],
            'a trailing tab is separator-shaped and dropped by the reader' => ["## x\t\n", "## x\n"],
            'a spaces-only run is normalized to one space' => ["##  h\n", "## h\n"],
        ];
    }

    #[DataProvider('headings')]
    public function testTheWriterKeepsWhatTheReaderKept(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::toCarve($source));
    }

    #[DataProvider('headings')]
    public function testTheFormattedHeadingRendersTheSame(string $source, string $expected): void
    {
        $converter = new CarveConverter();

        self::assertSame(
            $converter->convert($source),
            $converter->convert($expected),
        );
    }

    /**
     * The rendered heading is where the dropped character was visible.
     */
    public function testTheTabIsStillRenderedAfterAFormatRound(): void
    {
        $converter = new CarveConverter();

        self::assertSame(
            "<section id=\"x\">\n  <h2>\tx</h2>\n</section>\n",
            $converter->convert(CarveConverter::toCarve("## \tx\n")),
        );
    }
}
