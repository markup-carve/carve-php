<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A list marker on a block quote's lazy continuation is TEXT.
 *
 * PART 9 §10 I6: "the relation applies to EVERY open paragraph, including a
 * blockquote's lazy continuation". markup-carve/carve-js#1200 ruled it - the
 * quote's OPEN paragraph claims the line before the item's content column does -
 * and this engine opened a sub-list instead (markup-carve/carve-php#1575).
 *
 * IT IS THE SAME DERIVATION §24's S1 and S2 carry for the three opaque fence
 * bodies: a line is placed by the COLUMN it reaches, and neither step reads its
 * first character. So a marker at the item's content column under a quoted line
 * is the continuation that plain prose at that column is, and that one has
 * always folded here. Written flush left, `> q` over `- s` is one quoted
 * paragraph in this engine already; the same two lines inside an item have to be
 * one there too.
 *
 * IT IS THE QUOTE'S PARAGRAPH, NOT THE QUOTE, and that is the whole difficulty:
 * §24 C3 really does open a sublist for a marker at the content column under an
 * ordinary paragraph (markup-carve/carve#1517), so a guard reading "there is a
 * quote above" rather than "that quote has an open paragraph" takes markers it
 * must not. carve-js#1200 names four rows where the quote ends holding no
 * paragraph - a heading, a table, a blank quote line, a thematic break - and
 * every one of them still opens the sub-list.
 */
class AMarkerOnAQuoteLazyContinuationIsTextTest extends TestCase
{
    private function html(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');
    }

    /**
     * Documents whose marker line folds into the quote's paragraph, with the
     * HTML carve-js and carve-rs produce for each.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function foldingProvider(): array
    {
        return [
            'the reported document' => [
                "- > q\n  - s\ntail\n",
                '<ul> <li> <blockquote><p>q - s tail</p></blockquote> </li> </ul>',
            ],
            'an ordered marker' => [
                "- > q\n  1. s\ntail\n",
                '<ul> <li> <blockquote><p>q 1. s tail</p></blockquote> </li> </ul>',
            ],
            'a task marker' => [
                "- > q\n  - [ ] s\ntail\n",
                '<ul> <li> <blockquote><p>q - [ ] s tail</p></blockquote> </li> </ul>',
            ],
            // An abutting-attribute bullet is a marker too, and it reached this
            // gate by the same door.
            'an abutting-attribute bullet' => [
                "- > q\n  -{.x} s\ntail\n",
                '<ul> <li> <blockquote><p>q -{.x} s tail</p></blockquote> </li> </ul>',
            ],
            'an ordered host' => [
                "1. > q\n   - s\ntail\n",
                '<ol> <li> <blockquote><p>q - s tail</p></blockquote> </li> </ol>',
            ],
            'a nested quote' => [
                "- > > q\n  - s\ntail\n",
                '<ul> <li> <blockquote> <blockquote><p>q - s tail</p></blockquote> </blockquote> </li> </ul>',
            ],
            'a second quoted line above it' => [
                "- > q\n  > r\n  - s\ntail\n",
                '<ul> <li> <blockquote><p>q r - s tail</p></blockquote> </li> </ul>',
            ],
            'two items deep' => [
                "- - > q\n    - s\ntail\n",
                '<ul> <li> <ul> <li> <blockquote><p>q - s tail</p></blockquote> </li> </ul> </li> </ul>',
            ],
            // THE POST-BLANK DOOR. The collector that runs here does not break
            // at the marker - it injects a synthetic blank before it, which is
            // what makes the marker open a sublist - so the same defect had a
            // second spelling and needed the same guard.
            'reached after a blank line' => [
                "- x\n\n  > q\n  - s\ntail\n",
                '<ul> <li>x <blockquote><p>q - s tail</p></blockquote> </li> </ul>',
            ],
        ];
    }

    #[DataProvider('foldingProvider')]
    public function testTheMarkerFoldsIntoTheQuotedParagraph(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * THE NEAR MISS carve-js#1200 names. Where the quote ends on a block that
     * leaves no open paragraph there is nothing to fold into, so the marker
     * reaches the item body and §24 C3 opens the sub-list. A fix written against
     * "there is a quote above" breaks every one of these.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sublistProvider(): array
    {
        return [
            'a quote that ended on a heading' => [
                "- > # h\n  - s\ntail\n",
                '<ul> <li> <blockquote> <h1 id="h">h</h1> </blockquote> <ul> <li>s tail</li> </ul> </li> </ul>',
            ],
            'a quote that ended on a table' => [
                "- > | a |\n  - s\ntail\n",
                '<ul> <li> <blockquote> <table> <tbody> <tr><td>a</td></tr> </tbody> </table>'
                . ' </blockquote> <ul> <li>s tail</li> </ul> </li> </ul>',
            ],
            'a quote that ended on a blank quote line' => [
                "- > q\n  >\n  - s\ntail\n",
                '<ul> <li> <blockquote><p>q</p></blockquote> <ul> <li>s tail</li> </ul> </li> </ul>',
            ],
            'a quote that ended on a thematic break' => [
                "- > ---\n  - s\ntail\n",
                '<ul> <li> <blockquote> <hr> </blockquote> <ul> <li>s tail</li> </ul> </li> </ul>',
            ],
            'an empty quote' => [
                "- >\n  - s\ntail\n",
                '<ul> <li> <blockquote> </blockquote> <ul> <li>s tail</li> </ul> </li> </ul>',
            ],
            // The paragraph the marker would fold into has to be the QUOTE'S. One
            // line of prose after the quote makes it this item's again, and §24
            // C3 has it.
            'a prose line after the quote' => [
                "- > q\n  p\n  - s\ntail\n",
                '<ul> <li> <blockquote><p>q p</p></blockquote> <ul> <li>s tail</li> </ul> </li> </ul>',
            ],
            // ONE MARKER, NOT EVERY MARKER. The folded marker line is itself a
            // marker line, so it leaves the quote holding nothing for the next
            // one - which is where carve-js lands too.
            'a second marker below the folded one' => [
                "- > q\n  - s\n  - t\ntail\n",
                '<ul> <li> <blockquote><p>q - s</p></blockquote> <ul> <li>t tail</li> </ul> </li> </ul>',
            ],
        ];
    }

    #[DataProvider('sublistProvider')]
    public function testAQuoteWithNoOpenParagraphStillGivesTheMarkerASublist(
        string $source,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The two controls the rule is derived FROM, so a change to either shows up
     * here rather than as a surprise one layer in.
     */
    public function testTheTopLevelAndThePlainLineAreUnchanged(): void
    {
        $this->assertSame(
            '<blockquote><p>q - s tail</p></blockquote>',
            $this->html("> q\n- s\ntail\n"),
        );
        $this->assertSame(
            '<ul> <li> <blockquote><p>q s tail</p></blockquote> </li> </ul>',
            $this->html("- > q\n  s\ntail\n"),
        );
    }

    /**
     * BELOW the item's content column a marker was already lazy item text, and
     * that band does not move.
     */
    public function testAMarkerBelowTheContentColumnIsUnchanged(): void
    {
        $this->assertSame(
            '<ul> <li> <blockquote><p>q - s tail</p></blockquote> </li> </ul>',
            $this->html("- > q\n - s\ntail\n"),
        );
    }
}
