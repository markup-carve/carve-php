<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A `{...}` block-attribute line attaches to the block that FOLLOWS it, and a
 * nested list is a block (markup-carve/carve#1238).
 *
 * The target is the nested `<ul>`/`<ol>`: not the item, not the outer list. The
 * marker-abutting form `-{.x} item` is the separate mechanism that attributes
 * the `<li>`, and it is unaffected.
 *
 * carve-php attached the run when a blank line preceded it, and when the block
 * behind it was a paragraph, quote or fence, but dropped it before a nested
 * list written with no blank line. The item's body is not one stream: the
 * continuation collector stops at a marker reaching the item's content column
 * so the list parser can own the sub-list, and the run was cleared at that
 * CHUNK end as if the item had ended. The identical three lines one nesting
 * level up always attached.
 */
class AnAttributeBlockReachesANestedListTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    // ---- the fix: an attribute line with no blank before a nested list ----

    public function testAttributeLineReachesTheNestedListWithNoBlankBefore(): void
    {
        $result = $this->converter->convert("- a\n  {.x}\n  - b\n");

        $this->assertSame(
            "<ul>\n  <li>a\n    <ul class=\"x\">\n      <li>b</li>\n    </ul>\n  </li>\n</ul>\n",
            $result,
        );
    }

    public function testAttributeLineIsTheTailOfAChunkThatAlsoHoldsContent(): void
    {
        // The attribute line is not the whole chunk here: `para` precedes it.
        // A fix keyed on "the whole chunk is an attribute block" would leave
        // this one dropping.
        $result = $this->converter->convert("- a\n  para\n  {.x}\n  - b\n");

        $this->assertStringContainsString('<ul class="x">', $result);
    }

    public function testEveryAttributeOfTheRunReachesTheNestedList(): void
    {
        $result = $this->converter->convert("- a\n  {#i .x k=v}\n  - b\n");

        $this->assertStringContainsString('<ul id="i" class="x" k="v">', $result);
    }

    public function testTwoAttributeLinesMergeOntoTheNestedList(): void
    {
        $result = $this->converter->convert("- a\n  {.x}\n  {#i}\n  - b\n");

        $this->assertStringContainsString('<ul class="x" id="i">', $result);
    }

    public function testAnOrderedNestedListIsReachedTheSameWay(): void
    {
        $result = $this->converter->convert("1. a\n   {#n}\n   1. b\n");

        $this->assertStringContainsString('<ol id="n">', $result);
    }

    public function testTheThirdLevelIsReachedTheSameWay(): void
    {
        $result = $this->converter->convert("- a\n  - b\n    {.x}\n    - c\n");

        // Only the innermost list carries it.
        $this->assertSame(1, substr_count($result, 'class="x"'));
        $this->assertStringContainsString("<ul class=\"x\">\n          <li>c</li>", $result);
    }

    // ---- controls: shapes that already worked and must not move ----

    public function testBlankLineBeforeTheAttributeLineStillReachesTheNestedList(): void
    {
        // Row B. carve-php is the engine that already got this right; a
        // regression here would be worse than the bug being fixed.
        $result = $this->converter->convert("- a\n\n  {.x}\n  - b\n");

        $this->assertSame(
            "<ul>\n  <li>a\n    <ul class=\"x\">\n      <li>b</li>\n    </ul>\n  </li>\n</ul>\n",
            $result,
        );
    }

    public function testAttributeLineStillReachesAParagraphInTheItem(): void
    {
        $result = $this->converter->convert("- a\n  {.x}\n  para\n");

        $this->assertStringContainsString('<p class="x">para</p>', $result);
    }

    public function testAttributeLineStillReachesABlockQuoteInTheItem(): void
    {
        $result = $this->converter->convert("- a\n\n  {.x}\n  > q\n");

        $this->assertStringContainsString('<blockquote class="x">', $result);
    }

    public function testAttributeLineStillReachesACodeFenceInTheItem(): void
    {
        $result = $this->converter->convert("- a\n\n  {.x}\n  ```\n  code\n  ```\n");

        $this->assertStringContainsString('<pre class="x">', $result);
    }

    public function testAttributeLineStillReachesAListAtTopLevel(): void
    {
        // Row A.
        $result = $this->converter->convert("{.x}\n- b\n");

        $this->assertSame("<ul class=\"x\">\n  <li>b</li>\n</ul>\n", $result);
    }

    public function testAttributeLineAfterAParagraphStillReachesAListAtTopLevel(): void
    {
        // Row H: the identical three lines as the fixed shape, one level up.
        $result = $this->converter->convert("para\n{.x}\n- b\n");

        $this->assertSame("<p>para</p>\n<ul class=\"x\">\n  <li>b</li>\n</ul>\n", $result);
    }

    public function testTheMarkerAbuttingFormStillAttributesTheItem(): void
    {
        // A different mechanism targeting a different element.
        $result = $this->converter->convert("-{.x} item\n");

        $this->assertSame("<ul>\n  <li class=\"x\">item</li>\n</ul>\n", $result);
    }

    public function testTheMarkerAbuttingFormStillAttributesANestedItem(): void
    {
        $result = $this->converter->convert("- a\n  -{.x} b\n");

        $this->assertStringContainsString('<li class="x">b</li>', $result);
    }

    // ---- the guard: braces must be flush in the dedented chunk ----

    public function testAContinuationLinePastTheContentColumnIsNotAnAttributeBlock(): void
    {
        // Three spaces against a two-column content column leaves a residual
        // space, so this is a paragraph, not an attribute block. Corpus
        // 87-compact-list-blocks-10 pins it, and a fix that trimmed before
        // looking for the brace would delete this paragraph and re-tighten the
        // item.
        $result = $this->converter->convert("- a\n\n   {.c}\n");

        $this->assertSame(
            "<ul>\n  <li>a</li>\n</ul>\n",
            $result,
        );
    }

    public function testABraceWithContentOnTheMarkerLineStaysLiteral(): void
    {
        $result = $this->converter->convert("- {.notattr} Milk\n");

        $this->assertSame("<ul>\n  <li>{.notattr} Milk</li>\n</ul>\n", $result);
    }

    // ---- the run is scoped to the ITEM, and still ends with it ----

    public function testARunWithNoBlockLeftInTheItemDoesNotReachTheSiblingItem(): void
    {
        // §15 A4 (carve-php#757): the item boundary IS an end for the run.
        $result = $this->converter->convert("- a\n  {.x}\n- b\n");

        $this->assertSame("<ul>\n  <li>a</li>\n  <li>b</li>\n</ul>\n", $result);
    }

    public function testARunWithNoBlockLeftInTheItemDoesNotReachPastTheList(): void
    {
        $result = $this->converter->convert("- a\n  {.x}\n\npara\n");

        $this->assertSame("<ul>\n  <li>a</li>\n</ul>\n<p>para</p>\n", $result);
    }

    public function testARunLeftInANestedItemDoesNotReachTheNextOuterItem(): void
    {
        $result = $this->converter->convert("- a\n  - b\n    {.x}\n- c\n");

        $this->assertStringNotContainsString('class="x"', $result);
    }

    // ---- tight/loose does not move ----

    public function testTheItemStaysTightWhenTheNestedListIsAttributedWithNoBlank(): void
    {
        $result = $this->converter->convert("- a\n  {.x}\n  - b\n- c\n");

        $this->assertStringNotContainsString('<p>', $result);
        $this->assertStringContainsString('<ul class="x">', $result);
    }

    public function testTheItemStaysTightWhenTheNestedListIsAttributedAfterABlank(): void
    {
        // PART 9 §17 L2: a sub-block attached after a blank leaves the item
        // tight, and attributing that sub-block does not change it.
        $result = $this->converter->convert("- a\n\n  {.x}\n  - b\n- c\n");

        $this->assertStringNotContainsString('<p>', $result);
        $this->assertStringContainsString('<ul class="x">', $result);
    }

    public function testASecondParagraphAfterABlankStillLoosensTheItem(): void
    {
        $result = $this->converter->convert("- a\n\n  {.x}\n  para\n- c\n");

        $this->assertSame(
            "<ul>\n  <li><p>a</p>\n    <p class=\"x\">para</p>\n  </li>\n  <li><p>c</p></li>\n</ul>\n",
            $result,
        );
    }
}
