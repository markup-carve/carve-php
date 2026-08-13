<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Three rows of `markup-carve/carve#1028`, each an open fold that this engine
 * ended on one member of a set the grammar names as one.
 *
 * 1. A COMMENT AND A BLOCK-ATTRIBUTE LINE BELOW A BLOCK QUOTE. PART 2's LAZY
 *    CONTINUATION clause says a line continues the quote provided it is "not a
 *    block-opener: a heading, table, fenced code, `:::` div, thematic break, OR
 *    an 'invisible' reference / footnote / abbreviation definition OR COMMENT --
 *    each ends the blockquote and starts that block OUTSIDE it", and PART 9 §10
 *    I5 puts the block-attribute line in the same set. Only the abbreviation was
 *    tested for here, so `> quote` / `%% c` / `more` kept `more` INSIDE the
 *    quote as a second paragraph.
 *
 * 2. A BLOCK-ATTRIBUTE LINE BELOW A LIST ITEM. §10 I5 again, with I6 applying
 *    the relation to "EVERY open paragraph". The line stayed in the item, below
 *    its content column, and rendered as literal `{.cls}` text - so the reader
 *    saw the attribute source and the block it was written for carried no class.
 *    PART 2's LIST-ITEM ATTRIBUTES clause REJECTS that reading by name.
 *
 * 3. A CAPTION LINE BELOW A DEFINITION TERM. The reverse direction: the term
 *    ended where nothing opens. PART 9 §4 gives a caption five hosts and a
 *    definition term is none of them, so PART 2's `caption_slot` note makes the
 *    line "ordinary inline/paragraph content" - which `term_continuation_line`
 *    folds. This engine already folded a caption line into an open PARAGRAPH;
 *    only the term disagreed.
 */
class InvisibleLineEndsTheFoldTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testCommentLineEndsABlockQuotesLazyContinuation(): void
    {
        $this->assertSame(
            "<blockquote><p>quote</p></blockquote>\n<p>more</p>",
            trim($this->converter->convert("> quote\n%% c\nmore\n")),
        );
    }

    public function testBlockAttributeLineEndsABlockQuotesLazyContinuation(): void
    {
        $this->assertSame(
            "<blockquote><p>quote</p></blockquote>\n<p class=\"cls\">more</p>",
            trim($this->converter->convert("> quote\n{.cls}\nmore\n")),
        );
    }

    public function testAReferenceDefinitionStillEndsIt(): void
    {
        // The one member of the set that already worked. It is asserted so the
        // change is a widening rather than a swap.
        $this->assertSame(
            "<blockquote><p>quote</p></blockquote>\n<p>more</p>",
            trim($this->converter->convert("> quote\n[r]: /u\nmore\n")),
        );
    }

    public function testAPlainLineStillContinuesTheQuote(): void
    {
        $this->assertSame(
            '<blockquote><p>quote' . "\n" . 'more</p></blockquote>',
            trim($this->converter->convert("> quote\nmore\n")),
        );
    }

    public function testBlockAttributeLineEndsAListItemAndFloatsForward(): void
    {
        $this->assertSame(
            "<ul>\n  <li>item</li>\n</ul>\n<blockquote class=\"cls\"><p>quote</p></blockquote>",
            trim($this->converter->convert("- item\n{.cls}\n> quote\n")),
        );
        $this->assertSame(
            "<ul>\n  <li>item</li>\n</ul>\n<p class=\"cls\">more</p>",
            trim($this->converter->convert("- item\n{.cls}\nmore\n")),
        );
    }

    public function testAnIndentedAttributeLineStaysInsideTheItem(): void
    {
        // The control: at the content column the line is the item's, and §15
        // floats it onto the item's own next block.
        $this->assertSame(
            "<ul>\n  <li>item\n    <blockquote class=\"cls\"><p>quote</p></blockquote>\n  </li>\n</ul>",
            trim($this->converter->convert("- item\n  {.cls}\n  > quote\n")),
        );
    }

    public function testAnInvalidBraceLineStillFoldsAsItemText(): void
    {
        // §15's disambiguation: an INVALID block (`{# id}` - a space-broken id)
        // is not an attribute line, so it is ordinary text and must still fold
        // into the item. `{not attrs}` does NOT serve here: two boolean
        // attributes are a valid block, and all three engines drop it as
        // dangling.
        $this->assertStringContainsString(
            '{# id}',
            trim($this->converter->convert("- item\n{# id}\n")),
        );
    }

    public function testACaptionLineFoldsIntoADefinitionTerm(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>term\n^ cap</dt>\n</dl>",
            trim($this->converter->convert(":: term\n^ cap\n")),
        );
    }

    public function testACaptionLineStillAttachesToABlockQuote(): void
    {
        // The arm the caption test was written for is untouched: a quote IS one
        // of §4's five hosts, so the caption ends the fold and attaches. What
        // it attaches AS changed - §4a makes it the quote's attribution, not a
        // figure caption (carve#1159) - and the fold behavior is the same.
        $this->assertStringContainsString(
            '<footer>cap</footer>',
            trim($this->converter->convert("> quote\n^ cap\n")),
        );
    }

    public function testABlockOpenerStillEndsADefinitionTerm(): void
    {
        $this->assertStringContainsString(
            '<h1>head</h1>',
            trim($this->converter->convert(":: term\n# head\n")),
        );
    }
}
