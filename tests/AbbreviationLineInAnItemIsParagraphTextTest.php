<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An abbreviation-shaped line inside a list item is the paragraph it renders
 * (markup-carve/carve#1267).
 *
 * PART 12 §7: `*[TERM]: expansion` is an `abbreviation_definition` only as a
 * direct child of the document -- "written inside a block quote, a list item or
 * a div, the line is not a definition at all: it is ordinary paragraph text, it
 * defines nothing, and it is preserved as the text the author typed".
 *
 * This engine rendered that text and, when deciding the item's looseness,
 * counted the same line among the INVISIBLE constructs: the bucket holding
 * comments, reference definitions, footnote definitions and attribute lines,
 * all of which really do render nothing. So a line that produces output was
 * scored as producing none, and §17 L1 -- "some item holds a blank-line-
 * separated second paragraph" -- was answered on a fact that was not true.
 * That is the markup-carve/carve#755 shape.
 *
 * The controls below are the point of the set: the definition kinds that ARE
 * collected at an item's content column keep the item tight, so the
 * abbreviation's answer follows from §7 rather than from an inconsistency
 * between definition kinds.
 */
class AbbreviationLineInAnItemIsParagraphTextTest extends TestCase
{
    /**
     * @return void
     */
    public function testAbbreviationLineAfterABlankLoosensTheItem(): void
    {
        $html = (new CarveConverter())->convert("- a\n\n  *[A]: a\n");

        $this->assertSame(
            "<ul>\n  <li><p>a</p>\n    <p>*[A]: a</p>\n  </li>\n</ul>\n",
            $html,
        );
    }

    /**
     * A sub-block attached after it does not take the second paragraph back:
     * the definition-shaped line already was one (§17 L2 governs the sub-block,
     * not the line before it).
     *
     * @return void
     */
    public function testASublistAfterTheLineDoesNotRestoreTightness(): void
    {
        $html = (new CarveConverter())->convert("- a\n\n  *[A]: a\n  - b\n");

        $this->assertStringContainsString('<li><p>a</p>', $html);
        $this->assertStringContainsString('<p>*[A]: a</p>', $html);
    }

    /**
     * Looseness is a property of the LIST, so the sibling is wrapped too.
     *
     * @return void
     */
    public function testTheSiblingItemIsWrappedAsWell(): void
    {
        $html = (new CarveConverter())->convert("- a\n\n  *[A]: a\n  - b\n- c\n");

        $this->assertStringContainsString('<li><p>c</p></li>', $html);
    }

    /**
     * Control: a reference definition at the same column IS collected, renders
     * nothing, resolves for the rest of the document, and leaves the item tight.
     *
     * @return void
     */
    public function testAReferenceDefinitionAtTheSameColumnKeepsTheItemTight(): void
    {
        $html = (new CarveConverter())->convert("- a\n\n  [r]: /u\n\nSee [x][r].\n");

        $this->assertStringContainsString('<li>a</li>', $html);
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    /**
     * Control: a footnote definition, likewise.
     *
     * @return void
     */
    public function testAFootnoteDefinitionAtTheSameColumnKeepsTheItemTight(): void
    {
        $html = (new CarveConverter())->convert("- a\n\n  [^f]: note\n");

        $this->assertStringContainsString('<li>a</li>', $html);
    }

    /**
     * Control: a comment, likewise - the case the invisible bucket was built
     * for.
     *
     * @return void
     */
    public function testACommentAtTheSameColumnKeepsTheItemTight(): void
    {
        $html = (new CarveConverter())->convert("- a\n\n  %% hidden\n");

        $this->assertStringContainsString('<li>a</li>', $html);
    }

    /**
     * Control: at document level the abbreviation IS a definition - collected,
     * rendering nothing of its own, and expanding its term.
     *
     * @return void
     */
    public function testAtDocumentLevelTheDefinitionIsCollected(): void
    {
        $html = (new CarveConverter())->convert("*[A]: alpha\n\nA here\n");

        $this->assertSame("<p><abbr title=\"alpha\">A</abbr> here</p>\n", $html);
    }

    /**
     * The no-blank-line variant is a different clause and unchanged: the line
     * folds into the item as lazy text and the item stays tight
     * (corpus 194-an-abbreviation-at-a-list-item-s-content-column-is-still-not-a-definition).
     *
     * @return void
     */
    public function testTheLazyContinuationVariantStaysTight(): void
    {
        $html = (new CarveConverter())->convert("- a\n  *[HTML]: Hyper Text\n\nThe HTML spec.\n");

        $this->assertStringContainsString("<li>a\n*[HTML]: Hyper Text</li>", $html);
        $this->assertStringNotContainsString('<abbr', $html);
    }
}
