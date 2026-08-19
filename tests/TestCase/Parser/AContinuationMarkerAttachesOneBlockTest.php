<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Text;
use PHPUnit\Framework\TestCase;

/**
 * A continuation marker attaches ONE block, and that block's extent is the end.
 *
 * PART 9 §17 L3. Both collectors - the list-item form under a marker line and
 * the un-prefixed form under a quoted line - used to run to the next CONTAINER
 * marker instead: a blank line, a dedent, a sibling marker, another `+`. That is
 * not the same boundary, so whatever was written under the attached block came
 * along with it.
 *
 * WHAT THE EXTENT IS, BY KIND. The corpus pins the PARAGRAPH rows (category
 * 327) and the multi-line rows that must NOT be cut (88-3, 285, 327-4). It pins
 * nothing for the rest, and the rest is where the first two attempts went
 * wrong - so those rows are here. Every one of them is measured against
 * carve-rs.
 *
 *  - PARAGRAPH: ends at an INTERRUPTING line (§10), which is not the same as a
 *    block-opening line. A list marker opens a block and does not interrupt a
 *    paragraph, so it must not end the run.
 *  - An ATTRIBUTE LINE ends it too, and `startsNewBlock()` does not report one
 *    because that predicate answers "does a block start here" - an attribute
 *    belongs to the block BELOW it.
 *  - A CAPTION is the other direction: it ends a paragraph by ATTACHING to it,
 *    so it extends the attached block rather than starting a second one.
 *  - HEADING and THEMATIC BREAK are one line and done.
 *  - QUOTE, LIST, TABLE keep their own lines, and a completed TABLE is past
 *    when the rows stop while a QUOTE and a LIST still take lazy prose.
 */
class AContinuationMarkerAttachesOneBlockTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * A LIST MARKER DOES NOT INTERRUPT A PARAGRAPH, so it does not end the
     * attached one either. Asked with the block-opening predicate rather than
     * the interruption one, this split a paragraph that folds.
     */
    public function testAListMarkerUnderTheAttachedParagraphDoesNotEndIt(): void
    {
        $html = $this->html("> q\n+\npara\n- item\n");

        $this->assertSame(
            "<blockquote>\n"
            . "  <p>q</p>\n"
            . "  <p>para\n"
            . "- item</p>\n"
            . "</blockquote>\n",
            $html,
        );
    }

    /**
     * A HEADING IS ONE LINE. Read as a multi-line kind, the paragraph under an
     * attached heading was attached as a second block.
     */
    public function testAnAttachedHeadingEndsAtItsOwnLine(): void
    {
        $html = $this->html("- a\n+\n# H\npara\n");

        $this->assertStringContainsString("</h1>\n  </li>\n</ul>\n<p>para</p>", $html, $html);
    }

    /**
     * A THEMATIC BREAK is one line for the same reason.
     */
    public function testAnAttachedThematicBreakEndsAtItsOwnLine(): void
    {
        $html = $this->html("- a\n+\n---\npara\n");

        $this->assertStringContainsString("<hr>\n  </li>\n</ul>\n<p>para</p>", $html, $html);
    }

    /**
     * AN ATTRIBUTE AFTER THE ATTACHED PARAGRAPH BELONGS TO WHAT FOLLOWS. Left
     * in the run, the attribute was swallowed by the item and the heading it
     * was written for came out bare.
     */
    public function testAnAttributeAfterTheAttachedParagraphLeavesWithTheNextBlock(): void
    {
        $html = $this->html("- a\n+\npara\n{.x}\n# H\n");

        $this->assertStringContainsString('<h1 class="x">H</h1>', $html, $html);
    }

    /**
     * AN ATTRIBUTE BEFORE IT IS THE BLOCK'S OWN. The pair of the row above, and
     * the reason the attribute arm is not simply "an attribute ends the run"
     * (corpus 325 pins this half).
     */
    public function testAnAttributeBeforeTheAttachedBlockRidesWithIt(): void
    {
        $html = $this->html("- a\n+\n{.x}\n> q\n");

        $this->assertStringContainsString('<blockquote class="x">', $html, $html);
    }

    /**
     * A CAPTION EXTENDS THE ATTACHED BLOCK. `startsNewBlock()` reports it, so
     * the paragraph arm has to subtract it or the figure falls apart.
     */
    public function testACaptionUnderTheAttachedImageStaysWithIt(): void
    {
        $html = $this->html("- x\n+\n![a](i.png)\n^ cap\n");

        $this->assertStringContainsString('<figure>', $html, $html);
        $this->assertStringContainsString('<figcaption>cap</figcaption>', $html, $html);
    }

    /**
     * A QUOTE KEEPS ITS OWN LINES and still ends at an interrupting one.
     */
    public function testAnAttachedQuoteKeepsItsLinesAndEndsAtAHeading(): void
    {
        $this->assertStringContainsString("x\ny", $this->html("- a\n+\n> x\n> y\n- next\n"));

        $ended = $this->html("- a\n+\n> q\n# H\n");
        $this->assertStringContainsString("</blockquote>\n  </li>\n</ul>\n<section", $ended, $ended);
    }

    /**
     * A QUOTE AND A LIST STILL TAKE LAZY PROSE, which is what keeps the row
     * above from being "any block-shaped line ends a spanning block".
     */
    public function testAQuoteAndAListTakeLazyProse(): void
    {
        $this->assertStringContainsString("q\nprose", $this->html("- a\n+\n> q\nprose\n"));
        $this->assertStringContainsString("x\nprose", $this->html("- a\n+\n- x\nprose\n"));
    }

    /**
     * A COMPLETED TABLE HOLDS NO PARAGRAPH, so prose under it is a block of its
     * own - the same S4 question the rows above answer the other way.
     */
    public function testProseUnderAnAttachedTableIsNotAttached(): void
    {
        $html = $this->html("- a\n+\n| x | y |\nprose\n");

        $this->assertStringContainsString("</table>\n  </li>\n</ul>\n<p>prose</p>", $html, $html);
    }

    /**
     * A TABLE'S OWN SECOND ROW IS NOT A SECOND BLOCK.
     */
    public function testAnAttachedTableKeepsItsRows(): void
    {
        $html = $this->html("- +\n| a | b |\n| c | d |\n- next\n");

        $this->assertStringContainsString('<td>c</td>', $html, $html);
    }

    /**
     * A WRAPPED ATTRIBUTE BLOCK STAYS PENDING FOR ALL OF ITS LINES. `{.a` is
     * not a block-attribute LINE - it becomes one only when a later line closes
     * it - so a predicate that reads one line at a time says no to the opener
     * and no to every line after it. Settling the kind on the second line read
     * it as a paragraph, and the heading under it then ended the run: the
     * attributes were dropped and the block they belonged to left the item.
     */
    public function testAWrappedAttributeBlockStaysWithTheBlockItAttributes(): void
    {
        $html = $this->html("- +\n{.a\n.b}\n# H\n");

        $this->assertSame(
            "<ul>\n"
            . "  <li>\n"
            . '    <h1 class="a b" id="H">H</h1>' . "\n"
            . "  </li>\n"
            . "</ul>\n",
            $html,
        );
    }

    /**
     * A CAPTION EXTENDS A SPANNING BLOCK TOO, not only a paragraph. A table
     * leaves no open paragraph, so the arm that ends the run on that answered
     * first and the caption came back as literal text instead of becoming the
     * table's `<caption>`.
     */
    public function testACaptionUnderAnAttachedTableBecomesItsCaption(): void
    {
        $html = $this->html("- x\n+\n| a | b |\n^ cap\n");

        $this->assertStringContainsString('<caption>cap</caption>', $html, $html);
        $this->assertStringNotContainsString('^ cap', $html, $html);
    }

    /**
     * A TABLE CONTINUATION ROW IS MORE TABLE. `isTableRow()` reads only the
     * `|`-led form, so `+ c | d |` named no construct - and the row above it
     * leaves no open paragraph, so the run ended between a table and the row
     * that merges into it.
     */
    public function testAnAttachedTableKeepsItsContinuationRow(): void
    {
        $html = $this->html("- a\n+\n| x | y |\n+ c | d |\n");

        $this->assertStringContainsString('<td>x c</td><td>y d</td>', $html, $html);
        $this->assertStringNotContainsString('+ c', $html, $html);
    }

    /**
     * AN EXTENSION'S BLOCK HAS AN EXTENT THIS FILE CANNOT COMPUTE, so it is
     * left to the collectors' own container boundaries. Classified by the
     * BUILT-IN opener predicate alone, a registered matcher's opener read as a
     * paragraph and the run ended on the first block-shaped line in its body -
     * the matcher was then handed its opener alone and never fired.
     */
    public function testARegisteredMatcherSBlockIsNotCutAtItsBody(): void
    {
        $converter = new CarveConverter();
        $converter->getParser()->addBlockPattern(
            '/^@@spoiler\s*$/',
            function (array $lines, int $start, $parent, $parser): ?int {
                $end = $start + 1;
                $lineCount = count($lines);
                while ($end < $lineCount && trim($lines[$end]) !== '@@') {
                    $end++;
                }
                if ($end >= $lineCount) {
                    return null;
                }
                $para = new Paragraph();
                $para->appendChild(new Text('SPOILER'));
                $parent->appendChild($para);

                return ($end - $start) + 1;
            },
        );

        $html = $converter->convert("- a\n+\n@@spoiler\n# body\n@@\n");

        $this->assertStringContainsString('SPOILER', $html, $html);
        $this->assertStringNotContainsString('<h1', $html, $html);
        $this->assertStringNotContainsString('@@', $html, $html);
    }

    /**
     * THE QUOTE SPELLING GETS THE SAME ROWS, because it is the same rule and it
     * used to be a second copy of the boundary. Removing the rule from this
     * collector alone left the corpus green.
     */
    public function testTheQuoteSpellingEndsAtTheSameBoundary(): void
    {
        $attr = $this->html("> quoted\n+\n{.x}\n# H\n");
        $this->assertSame(
            "<blockquote>\n"
            . "  <p>quoted</p>\n"
            . '  <h1 class="x" id="H">H</h1>' . "\n"
            . "</blockquote>\n",
            $attr,
        );

        // ASSERTED WHOLE: cut after `- a`, the second item reopens as a
        // top-level list and every "contains `<li>b</li>`" row still passes.
        $list = $this->html("> quoted\n+\n- a\n- b\n");
        $this->assertSame(
            "<blockquote>\n"
            . "  <p>quoted</p>\n"
            . "  <ul>\n"
            . "    <li>a</li>\n"
            . "    <li>b</li>\n"
            . "  </ul>\n"
            . "</blockquote>\n",
            $list,
        );
    }
}
