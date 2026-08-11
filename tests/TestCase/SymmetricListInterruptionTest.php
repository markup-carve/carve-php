<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Symmetric list interruption (prototype).
 *
 * A list marker never interrupts an open paragraph: a bullet (`-`/`*`) needs a
 * blank line before it, exactly like an ordered marker (`1.`/`a.`/`i.`) already
 * does. Tight nested lists are unaffected -- sublist nesting is driven by
 * indentation inside an open list item, not by paragraph interruption.
 */
class SymmetricListInterruptionTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * Neither a bullet nor an ordered marker interrupts running prose; both fold.
     */
    public function testNoMarkerInterruptsProseWithoutBlankLine(): void
    {
        $this->assertStringNotContainsString('<ul>', $this->converter->convert("intro text\n- a"));
        $this->assertStringNotContainsString('<ol>', $this->converter->convert("intro text\n1. a"));
    }

    /**
     * A blank line before the list starts it -- bullet and ordered alike.
     */
    public function testBlankLineStartsEitherList(): void
    {
        $this->assertSame(
            "<p>intro text</p>\n<ul>\n  <li>a</li>\n</ul>\n",
            $this->converter->convert("intro text\n\n- a"),
        );
        $this->assertSame(
            "<p>intro text</p>\n<ol>\n  <li>a</li>\n</ol>\n",
            $this->converter->convert("intro text\n\n1. a"),
        );
    }

    /**
     * A thematic break still interrupts a paragraph (it is not a list marker).
     */
    public function testThematicBreakStillInterrupts(): void
    {
        $this->assertSame(
            "<p>intro</p>\n<hr>\n<p>more</p>\n",
            $this->converter->convert("intro\n---\nmore"),
        );
    }

    /**
     * Tight nested lists keep working with no blank lines -- unordered.
     */
    public function testTightUnorderedNestingPreserved(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n    <ul>\n      <li>tight</li>\n    </ul>\n  </li>\n  <li>list</li>\n</ul>\n",
            $this->converter->convert("- a\n  - tight\n- list"),
        );
    }

    /**
     * Tight nested lists keep working with no blank lines -- ordered.
     */
    public function testTightOrderedNestingPreserved(): void
    {
        $this->assertSame(
            "<ol>\n  <li>a\n    <ol start=\"2\">\n      <li>inner</li>\n    </ol>\n  </li>\n  <li>list</li>\n</ol>\n",
            $this->converter->convert("1. a\n   2. inner\n2. list"),
        );
    }

    /**
     * Mixed nesting (bullet outer, ordered inner) is preserved with no blanks.
     */
    public function testMixedNestingPreserved(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n    <ol>\n      <li>one</li>\n      <li>two</li>\n    </ol>\n  </li>\n  <li>b</li>\n</ul>\n",
            $this->converter->convert("- a\n  1. one\n  2. two\n- b"),
        );
    }

    /**
     * A list marker ENDS an open heading and starts a sibling list (bullet and
     * ordered alike) -- it folds only into a paragraph, not a heading. A
     * blockquote, by contrast, is also ended. Plain text would fold in.
     */
    public function testListMarkerEndsHeadingAndStartsSiblingList(): void
    {
        $this->assertSame(
            "<section id=\"T\">\n  <h1>T</h1>\n  <ul>\n    <li>item</li>\n  </ul>\n</section>\n",
            $this->converter->convert("# T\n- item"),
        );
        $this->assertSame(
            "<section id=\"T\">\n  <h1>T</h1>\n  <ol>\n    <li>one</li>\n  </ol>\n</section>\n",
            $this->converter->convert("# T\n1. one"),
        );
    }

    /**
     * A list marker on a lazy line FOLDS into the open quoted paragraph as
     * literal text, mirroring the top-level rule where a list marker does not
     * interrupt an open paragraph. It does not end the quote or start a sibling
     * list. (A heading, by contrast, IS ended by a list marker -- see
     * testListMarkerEndsHeadingAndStartsSiblingList.)
     */
    public function testListMarkerFoldsIntoOpenQuotedParagraph(): void
    {
        $this->assertSame(
            "<blockquote><p>quoted\n- item</p></blockquote>\n",
            $this->converter->convert("> quoted\n- item"),
        );
    }

    /**
     * Rule B: a list opens at any indentation, so a marker that dedents below an
     * indented list's base column starts a NEW sibling list (distinct base
     * columns are distinct lists) -- it is neither nested nor folded into the
     * previous item. Matches carve-js.
     */
    public function testDedentBelowIndentedBaseStartsSiblingList(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a</li>\n  <li>b</li>\n</ul>\n<ul>\n  <li>c</li>\n</ul>\n",
            $this->converter->convert("  - a\n  - b\n- c"),
        );
        // A single indented item then a dedented one: still two sibling lists.
        $this->assertSame(
            "<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b</li>\n</ul>\n",
            $this->converter->convert("  - a\n- b"),
        );
        // A normal col-0 list with a child then a col-0 sibling is unaffected.
        $this->assertSame(
            "<ul>\n  <li>a\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n  <li>c</li>\n</ul>\n",
            $this->converter->convert("- a\n  - b\n- c"),
        );
        // Plain text dedented below the base is NOT a marker: it lazily continues
        // the item (CommonMark lazy continuation), it does not end the list.
        $this->assertSame(
            "<ul>\n  <li>a\ncontinued</li>\n</ul>\n",
            $this->converter->convert("  - a\n continued"),
        );
    }

    /**
     * An indented list after a reference or abbreviation definition is a list,
     * not a definition continuation -- bullet and ordered alike. (Before this
     * change ordered was already swallowed here; the fix makes both consistent.)
     */
    public function testListAfterDefinitionIsNotSwallowed(): void
    {
        $this->assertSame(
            "<ul>\n  <li>item</li>\n</ul>\n",
            $this->converter->convert("[x]: /u\n  - item"),
        );
        $this->assertSame(
            "<ol>\n  <li>item</li>\n</ol>\n",
            $this->converter->convert("[x]: /u\n  1. item"),
        );
        $this->assertSame(
            "<ul>\n  <li>item</li>\n</ul>\n",
            $this->converter->convert("*[HTML]: HyperText\n  - item"),
        );
        $this->assertSame(
            "<ol>\n  <li>item</li>\n</ol>\n",
            $this->converter->convert("*[HTML]: HyperText\n  1. item"),
        );
    }

    /**
     * A list marker reaching the item's content column starts a sublist even
     * when an open continuation paragraph precedes it (PART 0 S3, PART 9 §24
     * C3; corpus 131) - bullet and ordered alike. The no-interrupt rule covers
     * below-content-column and top-level markers only.
     */
    public function testSublistMarkerInterruptsContinuationParagraph(): void
    {
        $expectedBullet = "<ul>\n"
            . "  <li><p>first</p>\n"
            . "    <p>second</p>\n"
            . "    <ul>\n"
            . "      <li>nested</li>\n"
            . "    </ul>\n"
            . "  </li>\n"
            . "</ul>\n";
        $this->assertSame($expectedBullet, $this->converter->convert("- first\n\n  second\n  - nested\n"));

        $expectedOrdered = "<ul>\n"
            . "  <li><p>first</p>\n"
            . "    <p>second</p>\n"
            . "    <ol>\n"
            . "      <li>nested</li>\n"
            . "    </ol>\n"
            . "  </li>\n"
            . "</ul>\n";
        $this->assertSame($expectedOrdered, $this->converter->convert("- first\n\n  second\n  1. nested\n"));
    }

    /**
     * Guards around the new nesting rule: plain prose still folds, and tight
     * nested lists stay tight (no loosening blank injected between siblings).
     */
    public function testContinuationParagraphGuards(): void
    {
        $this->assertSame(
            "<ul>\n  <li><p>first</p>\n    <p>second\nmore prose</p>\n  </li>\n</ul>\n",
            $this->converter->convert("- first\n\n  second\n  more prose\n"),
        );
        $this->assertSame(
            "<ul>\n  <li>fruit\n    <ul>\n      <li>apples</li>\n      <li>oranges</li>\n    </ul>\n  </li>\n</ul>\n",
            $this->converter->convert("- fruit\n  - apples\n  - oranges\n"),
        );
    }
}
