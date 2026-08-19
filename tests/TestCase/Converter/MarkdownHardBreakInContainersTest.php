<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A Markdown hard break inside a container is still a hard break.
 *
 * The top-level case landed in markup-carve/carve-php#1205, but the condition
 * asked `continuesParagraph()` about the raw next line. Inside a container that
 * is the wrong question: the next line of a quoted paragraph begins with `>`,
 * which reads as a new block, and a list item was excluded outright. Both
 * dropped the break that carve-js and carve-rs keep.
 */
class MarkdownHardBreakInContainersTest extends TestCase
{
    private MarkdownToCarve $converter;

    protected function setUp(): void
    {
        $this->converter = new MarkdownToCarve();
    }

    public function testABreakInsideABlockQuoteSurvives(): void
    {
        $this->assertSame("> a\\\n> b\n", $this->converter->convert("> a  \n> b\n"));
    }

    public function testABreakInsideAListItemSurvives(): void
    {
        $this->assertSame("- a\\\n  b\n", $this->converter->convert("- a  \n  b\n"));
    }

    /**
     * BOUND, and the row the list rule has to keep getting right: two adjacent
     * ITEMS are two paragraphs, so there is nothing to break. A fix that simply
     * removed the list exclusion breaks this.
     */
    public function testTwoAdjacentItemsAreNotJoined(): void
    {
        $this->assertSame("- a  \n- b\n", $this->converter->convert("- a  \n- b\n"));
        $this->assertSame("1. a  \n2. b\n", $this->converter->convert("1. a  \n2. b\n"));
    }

    /**
     * BOUND: a blank line ends the quoted paragraph, so the two halves are
     * separate and the trailing run stays literal.
     */
    public function testABlankLineInsideAQuoteEndsTheParagraph(): void
    {
        $this->assertSame("> a  \n\n> b\n", $this->converter->convert("> a  \n\n> b\n"));
    }

    /**
     * BOUND: the top-level behavior from #1205 is unchanged by this.
     */
    public function testTheTopLevelBreakIsUnchanged(): void
    {
        $this->assertSame("a\\\nb\n", $this->converter->convert("a  \nb\n"));
        $this->assertSame("a  \n", $this->converter->convert("a  \n"));
    }
}
