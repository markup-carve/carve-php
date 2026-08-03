<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Strict content-column rule for a colon-fence opened inside a list item, and
 * outer-item looseness from an internal blank before the item's own attached
 * block.
 *
 * Both verified against carve-js and the executable-spec oracle.
 *
 * A block-level construct opens only at its container's content column. A
 * `::: note` / bare `:::` opener on a list item's marker line opens at the item
 * content column; when its body and closer sit BELOW that column the div does
 * not form -- the whole run is literal text inside the `<li>`.
 *
 * An item that owns an internal blank line before a subsequent block that
 * attaches to the item (dedented below the sub-list's content column) is loose:
 * its first paragraph is wrapped. Nested-item looseness does not propagate to
 * the outer item (corpus 142).
 */
class StrictColumnAndOuterLoosenessTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    protected function assertHtml(string $expected, string $carve): void
    {
        $this->assertSame(trim($expected), trim($this->converter->convert($carve)));
    }

    public function testColonFenceBodyBelowContentColumnIsLiteral(): void
    {
        // `:::` opens at content column 2; body `- para text` and closer `:::`
        // sit at column 1, below it -> the div never forms, the run is literal.
        $this->assertHtml(
            "<ul>\n  <li>::: note\n- para text\n:::</li>\n</ul>",
            "- ::: note\n - para text\n :::\n",
        );
    }

    public function testColonFenceListBodyBelowContentColumnIsLiteral(): void
    {
        $this->assertHtml(
            "<ul>\n  <li>::: note\n- one\n- two\n:::</li>\n</ul>",
            "- ::: note\n - one\n - two\n :::\n",
        );
    }

    public function testColonFenceBodyAtContentColumnStillFormsDiv(): void
    {
        // Control: with the body and closer AT the content column the admonition
        // forms as before -- the strict rule only suppresses below-column bodies.
        $this->assertHtml(
            "<ul>\n  <li>\n    <aside class=\"admonition note\">\n"
                . "      <p>para text</p>\n    </aside>\n  </li>\n</ul>",
            "- ::: note\n  para text\n  :::\n",
        );
    }

    public function testOuterItemLooseFromInternalBlankBeforeAttachedBlock(): void
    {
        // The nested list `- b` is tight; the blank then `> q` (dedented below
        // the nested content column) attaches to the OUTER item, which owns the
        // blank -> the outer item is loose (its first paragraph is wrapped).
        $this->assertHtml(
            "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>b</li>\n    </ul>\n    <p>&gt; q</p>\n  </li>\n</ul>",
            "- a\n  - b\n\n   > q\n",
        );
    }

    public function testMarkerLineSubListLeadLoosensAtOuterContentColumn(): void
    {
        // The sub-list opens on the MARKER LINE, so the item is built from one
        // combined stream and used to skip the looseness scan entirely. The
        // outer item still holds two blocks separated by a blank -- the sub-list
        // and `Body.` at ITS content column -- so it is loose (carve-php#681).
        $this->assertHtml(
            "<ul>\n  <li>\n    <ul>\n      <li>a</li>\n    </ul>\n    <p>Body.</p>\n  </li>\n</ul>",
            "- - a\n\n  Body.\n",
        );
    }

    public function testMarkerLineSubListLeadDoesNotTakeInnerLooseness(): void
    {
        // Control at the INNER item's content column: the body belongs to the
        // sub-list, which loosens itself; that does not propagate outwards.
        $this->assertHtml(
            "<ul>\n  <li>\n    <ul>\n      <li><p>a</p>\n"
                . "        <p>Body.</p>\n      </li>\n    </ul>\n  </li>\n</ul>",
            "- - a\n\n    Body.\n",
        );
    }

    public function testMarkerLineSubListLeadStaysTightWithoutABlank(): void
    {
        // Control: the loosening must come from the BLANK, not from the lead.
        $this->assertHtml(
            "<ul>\n  <li>\n    <ul>\n      <li>a\nBody.</li>\n    </ul>\n  </li>\n</ul>",
            "- - a\n  Body.\n",
        );
    }

    public function testNestedItemLoosenessDoesNotPropagate(): void
    {
        // Corpus 142: `> q` at column 4 is the nested content column, so it
        // attaches to the nested item `b`; neither list goes loose.
        $this->assertHtml(
            "<ul>\n  <li>a\n    <ul>\n      <li>b\n"
                . "        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>",
            "- a\n  - b\n\n    > q\n",
        );
    }
}
