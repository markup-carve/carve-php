<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A raw block's interior lines are passed through verbatim (carve#800).
 *
 * ```` ```=html ```` means "these bytes reach the target unchanged". This
 * renderer indents block output line by line after the fact, which cannot tell
 * a raw block's interior from ordinary block markup - so every line of a
 * multi-line raw block gained the container's columns, and bytes the author
 * wrote came out different (carve-php#907).
 *
 * Inside a `<pre>` those columns are CONTENT, so the rendered code block shows
 * text the source never had. A `<pre>` guard already existed in the indenters
 * and covered that one case by pattern-matching the tag; the rule is about raw
 * blocks, not about `<pre>`, and the guard missed every raw block that does not
 * open one.
 *
 * The OPENING position is still indented, because that is where a block goes
 * and every other block type gets it. carve-js and carve-rs both read it this
 * way (markup-carve/carve#800).
 */
class ARawBlockInteriorIsVerbatimTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testInteriorLinesKeepTheirOwnColumnsInsideAFootnote(): void
    {
        $html = $this->html("[^a]: note\n\n  ```=html\n  <b>x</b>\n  <i>y</i>\n  ```\n\nsee[^a]\n");

        $this->assertStringContainsString("      <b>x</b>\n<i>y</i>", $html);
    }

    public function testInteriorLinesKeepTheirOwnColumnsInsideABlockQuote(): void
    {
        // A second container, because a fix that special-cases the footnote
        // body leaves every other container wrong.
        $html = $this->html("> ```=html\n> <b>x</b>\n> <i>y</i>\n> ```\n");

        $this->assertStringContainsString("  <b>x</b>\n<i>y</i>", $html);
    }

    public function testAuthorWhitespaceInsideAPreSurvives(): void
    {
        // The case where the difference is content rather than layout: two
        // columns added here change what the rendered code block SAYS.
        $html = $this->html("[^a]: note\n\n  ```=html\n  <pre>\n  a\n    b\n  </pre>\n  ```\n\nsee[^a]\n");

        $this->assertStringContainsString("<pre>\na\n  b\n</pre>", $html);
    }

    public function testTheOpeningPositionIsStillIndented(): void
    {
        // The boundary. Leaving the block flush at column 0 also satisfies
        // every assertion above, and makes the raw block the one block type
        // whose placement ignores its container.
        $html = $this->html("[^a]: note\n\n  ```=html\n  <b>x</b>\n  ```\n\nsee[^a]\n");

        $this->assertStringContainsString("\n      <b>x</b>", $html);
    }

    public function testAnOrdinaryBlockIsStillIndentedOnEveryLine(): void
    {
        // The control: raw is the exception, not the new rule. A list inside a
        // footnote body still gets the body's columns on each of its lines.
        $html = $this->html("[^a]: note\n\n  - one\n  - two\n\nsee[^a]\n");

        $this->assertStringContainsString('        <li>one</li>', $html);
    }

    /**
     * The placement is about the block, not about the byte that opens it
     * (carve-php#2703). Every case above spells the payload `<b>x</b>`, so the
     * footnote body indenter could place a raw block by asking whether the line
     * starts with a tag and still satisfy all of them. A payload that opens with
     * plain text tells the two rules apart.
     */
    public function testAPlainTextPayloadIsPlacedAtTheBodyColumn(): void
    {
        $html = $this->html("[^a]: note\n\n  ```=html\n  x\n  ```\n\nsee[^a]\n");

        $this->assertStringContainsString("\n      x\n", $html);
    }

    public function testAPlainTextPayloadKeepsItsInteriorAtColumnZero(): void
    {
        // Both halves at once: the opening moves, the interior does not.
        $html = $this->html("[^a]: note\n\n  ```=html\n  x\n  y\n  ```\n\nsee[^a]\n");

        $this->assertStringContainsString("      x\ny\n", $html);
    }

    public function testAnInteriorLineOpeningWithATagStaysVerbatim(): void
    {
        // The inverse: a tag-leading line in the INTERIOR must not be placed.
        $html = $this->html("[^a]: note\n\n  ```=html\n  x\n  <b>y</b>\n  ```\n\nsee[^a]\n");

        $this->assertStringContainsString("      x\n<b>y</b>\n", $html);
    }

    public function testAPayloadWrittenPastTheFenceKeepsItsExtraColumns(): void
    {
        // The body column is added to what the author wrote, not substituted
        // for it, so two authored columns become eight.
        $html = $this->html("[^a]: note\n\n  ```=html\n    x\n  ```\n\nsee[^a]\n");

        $this->assertStringContainsString("\n        x\n", $html);
    }
}
