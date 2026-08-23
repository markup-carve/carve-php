<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A BBCode list keeps its STRUCTURE: nesting, an item's own blocks, and the
 * line between it and the list beside it.
 *
 * `convertLists()` was a pair of non-greedy regexes over flat text, so it could
 * see none of the three. An outer `[list]` closed on the INNER `[/list]` and
 * leaked a literal `[list]` into item one (carve-php#1623); an item's
 * continuation lines were written at column 0, where a blank line ends the list
 * and the next marker is just a line of the paragraph that follows
 * (carve-php#1623); and two adjacent `[list=1]` blocks merged into one `<ol>`,
 * because only the unordered path had a boundary axis and its axis was an
 * alternating marker (carve-php#1621).
 */
class BbcodeListsNestAndStaySeparateTest extends TestCase
{
    protected BbcodeToCarve $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new BbcodeToCarve();
    }

    protected function html(string $bbcode): string
    {
        return (new CarveConverter())->convert($this->converter->convert($bbcode));
    }

    public function testANestedListIsANestedList(): void
    {
        $bbcode = "[list]\n[*]outer one\n[list]\n[*]inner\n[/list]\n[*]outer two\n[/list]\n";

        $this->assertSame("- outer one\n  - inner\n- outer two\n", $this->converter->convert($bbcode));
        $this->assertStringNotContainsString('[list]', $this->converter->convert($bbcode));
    }

    public function testTheNestedListRendersInsideTheItemThatHoldsIt(): void
    {
        $html = $this->html("[list]\n[*]outer one\n[list]\n[*]inner\n[/list]\n[*]outer two\n[/list]\n");

        $collapsed = preg_replace('/\s+/', '', $html) ?? $html;
        $this->assertSame(
            '<ul><li>outerone<ul><li>inner</li></ul></li><li>outertwo</li></ul>',
            $collapsed,
        );
    }

    public function testAnItemsSecondParagraphStaysInTheItem(): void
    {
        $bbcode = "[list]\n[*]first para\n\nsecond para\n[*]second item\n[/list]\n";

        $this->assertSame("- first para\n\n  second para\n\n- second item\n", $this->converter->convert($bbcode));

        $collapsed = preg_replace('/\s+/', '', $this->html($bbcode)) ?? '';
        $this->assertSame(
            '<ul><li><p>firstpara</p><p>secondpara</p></li><li><p>seconditem</p></li></ul>',
            $collapsed,
        );
    }

    public function testTwoAdjacentOrderedListsStayTwoLists(): void
    {
        $bbcode = "[list=1]\n[*]one\n[/list]\n[list=1]\n[*]two\n[/list]\n";

        // PART 9 §11 N1a: three blank lines is the hard list boundary.
        $this->assertSame("1. one\n\n\n\n1. two\n", $this->converter->convert($bbcode));

        $collapsed = preg_replace('/\s+/', '', $this->html($bbcode)) ?? '';
        $this->assertSame('<ol><li>one</li></ol><ol><li>two</li></ol>', $collapsed);
    }

    public function testTwoAdjacentUnorderedListsStayTwoListsAndKeepTheirMarker(): void
    {
        $bbcode = "[list]\n[*]one\n[/list]\n[list]\n[*]two\n[/list]\n";

        // The marker is no longer spent on saying what the boundary says.
        $this->assertSame("- one\n\n\n\n- two\n", $this->converter->convert($bbcode));
    }

    /**
     * The alternating marker could only ever part TWO lists - a third took the
     * first list's marker back and merged with the second.
     */
    public function testThreeAdjacentListsStayThreeLists(): void
    {
        $bbcode = '[list][*]a[/list][list][*]b[/list][list][*]c[/list]';

        $collapsed = preg_replace('/\s+/', '', $this->html($bbcode)) ?? '';
        $this->assertSame('<ul><li>a</li></ul><ul><li>b</li></ul><ul><li>c</li></ul>', $collapsed);
    }

    /**
     * `[list=X]` is the ordered form whatever X is. Only `[list=1]` used to be
     * read as one, so `[list=a]` matched no branch: its tags were stripped and
     * two bare `[*]` markers on the text were then read as an emphasis pair.
     */
    public function testAnAlphaOrderedListIsAList(): void
    {
        $collapsed = preg_replace('/\s+/', '', $this->html("[list=a]\n[*]one\n[*]two\n[/list]\n")) ?? '';

        $this->assertSame('<ol><li>one</li><li>two</li></ol>', $collapsed);
    }

    // ==================== The bounds ====================

    /**
     * A LONE LIST TAKES NO BOUNDARY. The boundary parts siblings; emitted
     * unconditionally it would be three blank lines in every document.
     */
    public function testALoneListIsNotGivenABoundary(): void
    {
        $this->assertSame("- one\n- two\n", $this->converter->convert("[list]\n[*]one\n[*]two\n[/list]\n"));
    }

    /**
     * A TIGHT LIST STAYS TIGHT. The looseness comes from the item having a
     * blank line in it, not from the list having more than one item.
     */
    public function testAListWithNoBlankLineInAnyItemStaysTight(): void
    {
        $collapsed = preg_replace('/\s+/', '', $this->html("[list]\n[*]a\n[*]b\n[/list]\n")) ?? '';

        $this->assertSame('<ul><li>a</li><li>b</li></ul>', $collapsed);
    }

    /**
     * TWO LISTS WITH PROSE BETWEEN THEM ARE NOT ADJACENT. Only whitespace
     * between the blocks makes them siblings the parser would merge.
     */
    public function testTwoListsPartedByProseTakeNoBoundary(): void
    {
        $bbcode = "[list]\n[*]one\n[/list]\nbetween\n[list]\n[*]two\n[/list]\n";

        $this->assertSame("- one\n\nbetween\n\n- two\n", $this->converter->convert($bbcode));
    }

    /**
     * AN UNCLOSED LIST RUNS TO THE END OF THE INPUT, which is what an unclosed
     * quote does. It used to reach no branch at all, so its `[list]` opener was
     * left in the text - cleanup() strips `[/tag]` and `[tag=value]`, never a
     * bare `[tag]` - and the items stayed as literal `[*]` markers.
     */
    public function testAnUnclosedListIsStillAList(): void
    {
        $this->assertSame("- one\n- two\n", $this->converter->convert("[list]\n[*]one\n[*]two\n"));
    }

    /**
     * A LIST HOLDING NO ITEM IS NOT A LIST, and its text is kept: nothing the
     * author wrote leaves with the tags.
     */
    public function testAListWithNoItemKeepsItsText(): void
    {
        $this->assertSame("plain text\n", $this->converter->convert("[list]\nplain text\n[/list]\n"));
    }

    /**
     * A STRAY `[/list]` WITH NO OPEN LIST IS DROPPED, the way a stray
     * `[/quote]` is.
     */
    public function testAStrayCloserIsDropped(): void
    {
        $this->assertSame("text\n", $this->converter->convert('text[/list]'));
    }

    public function testAListStillGetsItsBlankLineAfterProse(): void
    {
        $this->assertStringContainsString(
            "Some text\n\n- item 1",
            $this->converter->convert('Some text[list][*]item 1[*]item 2[/list]'),
        );
    }
}
