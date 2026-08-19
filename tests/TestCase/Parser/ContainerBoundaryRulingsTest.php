<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three rulings that arrived with the bump to spec 5aab8fe.
 *
 * The corpus documents are the acceptance test and they live in the submodule;
 * these are the RULES behind them, spelled where the engine can be read against
 * them, plus the shapes each rule reaches that no corpus document pins.
 */
class ContainerBoundaryRulingsTest extends TestCase
{
    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * A LINE BLOCK HARDENS A SOFT BREAK AT EVERY DEPTH
     * (markup-carve/carve#1351).
     *
     * The promotion reached direct children only, which made the engine
     * contradict itself: the BACKSLASH spelling of a boundary already hardened
     * inside an emphasis run while the plain spelling of the same boundary did
     * not. One line boundary produces one `<br>`, however it is spelled.
     *
     * @return array<string, array{string, string}>
     */
    public static function nestedBreakProvider(): array
    {
        return [
            'plain boundary inside strong' => ["::: |\n*a\nb*\n:::\n", "<strong>a<br>\nb</strong>"],
            // The row that made the old reading self-contradictory: this one
            // already hardened, so the two spellings disagreed.
            'backslash boundary inside strong' => ["::: |\n*a\\\nb*\n:::\n", "<strong>a<br>\nb</strong>"],
            'inside emphasis' => ["::: |\n/a\nb/\n:::\n", "<em>a<br>\nb</em>"],
            'two containers deep' => ["::: |\n*/a\nb/*\n:::\n", "<em>a<br>\nb</em>"],
            'inside a link label' => ["::: |\n[a\nb](/u)\n:::\n", "a<br>\nb</a>"],
        ];
    }

    #[DataProvider('nestedBreakProvider')]
    public function testABreakHardensAtEveryDepth(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->html($source));
    }

    /**
     * THE EXEMPTION IS NODE-PRESENCE, NOT DEPTH.
     *
     * A verbatim run swallows the boundary into its own content, so there is no
     * soft break left to harden and no `<br>` to emit. Without this row the
     * rule above could be read as "every boundary in a line block hardens",
     * which is the reading that would put a `<br>` inside a code span.
     */
    public function testARunThatSwallowedTheBoundaryEmitsNoBreak(): void
    {
        $this->assertStringNotContainsString('<br>', $this->html("::: |\na `b\nc` d\n:::\n"));
    }

    /**
     * A TABLE IS A TABLE HOWEVER ITS LAST ROW IS SPELLED
     * (markup-carve/carve#1348).
     *
     * A continuation row carries no leading pipe, so the row test did not see
     * it and the container reported an open paragraph its table did not have.
     * The standard-row spelling of the same table already sent the tail out,
     * which is what made this a defect rather than a reading.
     *
     * @return array<string, array{string}>
     */
    public static function tableEndsOnAContinuationRowProvider(): array
    {
        return [
            'in a list item' => ["- | a |\n  + b |\ntail\n"],
            'in a quote' => ["> | a |\n> + b |\ntail\n"],
            'in a quote in a description' => [":: t\n:  > | a |\n   > + b |\ntail\n"],
            'in a description' => [":: t\n:  | a |\n   + b |\ntail\n"],
        ];
    }

    #[DataProvider('tableEndsOnAContinuationRowProvider')]
    public function testAContinuationRowLeavesNoOpenParagraph(string $source): void
    {
        $this->assertStringContainsString("</table>\n", $this->html($source));
        $this->assertStringEndsWith("<p>tail</p>\n", $this->html($source));
    }

    /**
     * ONLY WHERE A TABLE IS ABOVE IT (markup-carve/carve#1349).
     *
     * With no row above, `+ b |` is ordinary prose and the paragraph it belongs
     * to stays open, so a flush-left line still folds in. This is the control
     * the row above would take away if the continuation row were read as a
     * table wherever it appears.
     */
    public function testAContinuationRowWithNoTableAboveIsProse(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n+ b |\ntail</li>\n</ul>\n",
            $this->html("- a\n  + b |\ntail\n"),
        );
    }

    /**
     * AN INVISIBLE LINE AT A CONTAINER'S CONTENT COLUMN ENDS THE PARAGRAPH,
     * NOT THE CONTAINER (markup-carve/carve#1350).
     *
     * ASSERTED ON THE WHOLE DOCUMENT, because `tail` renders as `<p>tail</p>`
     * whether it lands at document level or folds into the container - a
     * containment check passes on exactly the defect it is meant to catch.
     *
     * @return array<string, array{string, string}>
     */
    public static function invisibleAtTheContentColumnProvider(): array
    {
        $item = "<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>\n";
        $description = "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p>tail</p>\n";

        return [
            'reference definition in an item' => ["- a\n  [r]: /u\ntail\n", $item],
            'footnote definition in an item' => ["- a\n  [^f]: t\ntail\n", $item],
            'reference definition in a description' => [":: t\n:  a\n   [r]: /u\ntail\n", $description],
            'comment in a description' => [":: t\n:  a\n   %% c\ntail\n", $description],
            // Not pinned by the corpus: #1350 is stated about a container's
            // content column rather than about descriptions, and nothing points
            // the other way. The LIST spelling of this one still folds and is
            // filed as markup-carve/carve-php#1421 - its tracker answers "is a
            // paragraph open" and "is the item still collecting" with one flag.
            'comment in a quote' => [
                "> a\n> %% c\ntail\n",
                "<blockquote><p>a</p></blockquote>\n<p>tail</p>\n",
            ],
        ];
    }

    #[DataProvider('invisibleAtTheContentColumnProvider')]
    public function testAnInvisibleLineAtTheContentColumnEndsTheParagraph(
        string $source,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * BELOW the column the same line is a LAZY continuation and still folds.
     *
     * The corpus pins both of these, and they are what keeps the rule about the
     * COLUMN rather than about the character.
     *
     * @return array<string, array{string, string}>
     */
    public static function invisibleBelowTheColumnProvider(): array
    {
        return [
            'comment at column 0' => ["- a\n%% c\nb\n", "<li>a\n    b\n  </li>"],
            'comment one column short' => ["- a\n %% c\nb\n", "<li>a\n    b\n  </li>"],
            // One column short of the item's content column, so it is not the
            // definition the column would have made it.
            'reference definition one column short' => ["- a\n [r]: /u\ntail\n", '[r]: /u'],
            // NOT AT THE COLUMN reads in both directions. A comment written
            // with a space of indentation INSIDE the quote is ordinary
            // paragraph text, exactly as an indented attribute line there is,
            // so a flush-left line still folds into the quote. This is the
            // control for the quote row above: without it the column test can
            // be dropped and every comment closes.
            'comment indented inside a quote' => [
                "> a\n>  %% c\ntail\n",
                "<blockquote>\n  <p>a</p>\n  <p>tail</p>\n</blockquote>\n",
            ],
        ];
    }

    #[DataProvider('invisibleBelowTheColumnProvider')]
    public function testAnInvisibleLineBelowTheColumnStillFolds(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->html($source));
    }

    /**
     * AN INVISIBLE BLOCK ENDS THE PARAGRAPH WITHOUT ENDING THE CONTAINER.
     *
     * Two questions, and one flag used to answer both
     * (markup-carve/carve-php#1421). A FLUSH-LEFT line still needs an open
     * paragraph, so it goes out; an INDENTED one does not, because it reaches
     * no content column but §24 C3 still reads it as the item's own block.
     * Closing the paragraph for both is what corpus 197 and 277 refuse.
     *
     * @return array<string, array{string, string}>
     */
    public static function invisibleKeepsTheItemCollectingProvider(): array
    {
        return [
            // corpus 197: the line after the comment is the item's SECOND
            // paragraph, not a continuation of the first.
            'comment then an indented line' => [
                "- a\n  %% x\n b\n\n- c\n",
                "<li><p>a</p>\n    <p>b</p>\n  </li>",
            ],
            // corpus 277: a below-column MARKER opens a nested list inside the
            // item, after a comment FENCE at the content column.
            'comment fence then a below-column marker' => [
                "- a\n  %%%\n  x\n  %%%\n - s\n",
                "<li>a\n    <ul>\n      <li>s</li>\n    </ul>\n  </li>",
            ],
        ];
    }

    #[DataProvider('invisibleKeepsTheItemCollectingProvider')]
    public function testAnInvisibleBlockDoesNotEndTheItem(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->html($source));
    }

    /**
     * AND THE QUOTE IT IS ASKED OF MAY ITSELF BE A QUOTE (PART 1 S4,
     * markup-carve/carve#1355).
     *
     * A quote's answer is its own last block's, and when that block is a QUOTE
     * the question moves in one. Asked only of the outer quote's own content,
     * `> > # H` read the inner `> # H` as prose - it starts with `>` and not
     * `#` - so the outer quote reported an open paragraph the flush-left line
     * folded into, while the same heading one level up already ended it.
     *
     * @return array<string, array{string}>
     */
    public static function nestedQuoteProvider(): array
    {
        return [
            'heading' => ["> > # H\ntail\n"],
            'table' => ["> > | a |\n> > | b |\ntail\n"],
            'thematic break' => ["> > ---\ntail\n"],
            // Three levels, which the rule does not count.
            'three levels' => ["> > > # H\ntail\n"],
            // An earlier paragraph in the OUTER quote does not change the
            // answer: the question is about the LAST block.
            'after a paragraph in the outer quote' => ["> p\n> > # H\ntail\n"],
            // The inner quote's table ends on a CONTINUATION row, which is only
            // visible with that quote's own line history.
            'inner table on a continuation row' => ["> > | a |\n> > + b |\ntail\n"],
        ];
    }

    #[DataProvider('nestedQuoteProvider')]
    public function testAQuoteIsAskedItsOwnBody(string $source): void
    {
        $this->assertStringEndsWith("<p>tail</p>\n", $this->html($source));
    }

    /**
     * The CONTROL one level up, which already answered.
     *
     * Without it the rows above pass on an engine that ends a quote on
     * anything, and the recursion would be doing no work.
     */
    public function testAOneLevelQuoteStillFoldsWhenItsLastBlockIsProse(): void
    {
        $this->assertSame(
            "<blockquote><p>p\ntail</p></blockquote>\n",
            $this->html("> p\ntail\n"),
        );
    }

    /**
     * THE BLOCK'S EXTENT IS THE DEFINITION'S, BLANK LINES AND ALL (PART 1 S4,
     * markup-carve/carve#1363).
     *
     * A blank inside a footnote body separates the NOTE's own blocks rather
     * than ending it. Three passes had to agree: the prepass that collects the
     * note, the item's own line collection, and the trailing-block tracker -
     * the prepass stopping at the blank while the block parser skipped past it
     * is what made the second block leave the document entirely.
     *
     * Settled by an internal contradiction rather than a count: this engine
     * ended the item on the contiguous spelling and folded the flush-left line
     * as soon as a blank sat between the note's blocks, so one definition
     * answered differently by how its own body was laid out.
     */
    public function testAFootnoteBodyRunsPastItsOwnBlankLines(): void
    {
        $html = $this->html("- a\n  [^f]: t\n\n    more\ntail\n\nx[^f]\n");

        // The item ends, so the flush-left line is top-level.
        $this->assertStringContainsString("<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>", $html);
        // And the note keeps BOTH blocks - the second one is the half that used
        // to leave the document entirely.
        $this->assertStringContainsString('<p>t</p>', $html);
        $this->assertStringContainsString('<p>more<a href="#fnref1"', $html);
    }

    /**
     * A LINK REFERENCE DEFINITION HAS NO BODY, and that difference is the whole
     * rule. Required rather than incidental: it is the control that catches a
     * fix written one construct too wide.
     */
    public function testALinkDefinitionOpensNoBodyRun(): void
    {
        $html = $this->html("- a\n  [r]: /u\n\n    more\ntail\n\n[r][]\n");

        // `more` and `tail` are the ITEM's, because the definition ended with
        // its own line.
        $this->assertStringContainsString('<p>more' . "\n" . 'tail</p>', $html);
        $this->assertStringContainsString('<a href="/u">r</a>', $html);
    }

    /**
     * The CONTIGUOUS spelling answers the same way, which is what makes the
     * pair one rule rather than two.
     */
    public function testAContiguousFootnoteBodyEndsTheItemToo(): void
    {
        $html = $this->html("- a\n  [^f]: t\n    more\ntail\n\nx[^f]\n");

        $this->assertStringContainsString("<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>", $html);
    }

    /**
     * THE ROW ABOVE IS THE ONE IN THE SAME CONTAINER (PART 9 §5 T6), and PROSE
     * REOPENS A CONTAINER'S PARAGRAPH (PART 1 S4) - which does not ask whether
     * that paragraph is the container's FIRST block
     * (markup-carve/carve-php#1436).
     *
     * A `+` line written in the ITEM is not the continuation row of a table
     * written inside a QUOTE the item holds. Handing the quote's table run
     * outward read it as one, so the item reported no open paragraph and the
     * flush-left line went out - where the `+` line is prose, it reopens the
     * item's paragraph, and the line folds in.
     *
     * @return array<string, array{string, bool}>
     */
    public static function continuationRowContainerProvider(): array
    {
        return [
            // The `+` is in the ITEM, the table in the quote: prose, so `tail`
            // folds in.
            'quote head, + written in the item' => ["- > | a |\n  + b |\ntail\n", false],
            // The same head under a definition description, which the ticket's
            // own two shapes could not show.
            'quote head, + written in the description' => [":: t\n:  > | a |\n   + b |\ntail\n", false],
            // The `+` is in the QUOTE, with the table: a real continuation row,
            // so nothing is open and `tail` goes out.
            'quote head, + written in the quote' => ["- > | a |\n  > + b |\ntail\n", true],
            'quote alone' => ["> | a |\n> + b |\ntail\n", true],
            // And the same container throughout, which never went through the
            // recursion at all.
            'item head, + written in the item' => ["- | a |\n  + b |\ntail\n", true],
        ];
    }

    #[DataProvider('continuationRowContainerProvider')]
    public function testAContinuationRowBelongsToItsOwnContainer(string $source, bool $tailIsOutside): void
    {
        $html = $this->html($source);
        $outside = preg_match('#</(?:ul|dl|blockquote)>\s*<p>tail</p>#s', $html) === 1;

        $this->assertSame($tailIsOutside, $outside, 'for: ' . var_export($source, true));
    }
}
