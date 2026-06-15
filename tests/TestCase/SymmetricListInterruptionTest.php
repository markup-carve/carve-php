<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
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
     * A bullet folds into an open heading, just like an ordered marker.
     */
    public function testBulletFoldsIntoHeading(): void
    {
        $this->assertSame(
            "<section id=\"t-item\">\n  <h1>T\n- item</h1>\n</section>\n",
            $this->converter->convert("# T\n- item"),
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
}
