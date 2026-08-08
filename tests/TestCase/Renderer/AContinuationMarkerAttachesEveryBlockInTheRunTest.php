<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 section 1: `to_html(fmt(x)) == to_html(x)`, on a block attached with
 * the continuation marker (PART 9 section 17).
 *
 * The writer converts a `+` attachment into indentation whenever the attached
 * block cannot fold into the paragraph above it. Two things it got wrong
 * (carve-php#1069 causes 3 and 4), which turn out to be one rule each:
 *
 * - a standalone `image` and a `figure` are written as a bare inline run on
 *   their own line, so at the item's content column they ARE lazy continuation.
 *   The `<figure>` disappeared and the caption came out as literal text.
 * - once one child is written at the marker column - column 0 - a later child at
 *   the item's content column is INDENTED relative to it and is absorbed as its
 *   lazy continuation. Only the last line of the attached run was indented, and
 *   a thematic break folded into the paragraph above it as an em dash.
 *
 * The condition the writer carried said the opposite in a comment: "only a
 * paragraph reaches this - no other attached kind can fold into an open
 * paragraph". That was a premise in the code, and measurement refuted it.
 */
class AContinuationMarkerAttachesEveryBlockInTheRunTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    protected function fmt(string $source): string
    {
        return CarveConverter::toCarve($source);
    }

    protected function assertRoundTrips(string $source): void
    {
        $once = $this->fmt($source);
        $this->assertSame($this->html($source), $this->html($once), 'to_html(fmt(x)) != to_html(x)');
        $this->assertSame($once, $this->fmt($once), 'fmt is not idempotent');
    }

    public function testAnAttachedImageWithACaptionKeepsItsFigure(): void
    {
        $source = "- x\n+\n![a](i.png)\n^ cap\n";
        $this->assertSame($source, $this->fmt($source));
        $this->assertRoundTrips($source);
        $out = $this->html($this->fmt($source));
        $this->assertStringContainsString('<figure>', $out);
        $this->assertStringContainsString('<figcaption>cap</figcaption>', $out);
        $this->assertStringNotContainsString('^ cap', $out);
    }

    public function testAnAttachedImageWithoutACaptionStaysABlockImage(): void
    {
        $source = "- x\n+\n![a](i.png)\n";
        $this->assertSame($source, $this->fmt($source));
        $this->assertRoundTrips($source);
    }

    public function testEveryLaterChildOfTheRunIsWrittenAtTheMarkerColumnToo(): void
    {
        $source = "- x\n+\n---yaml\nk: v\n---\n";
        $this->assertRoundTrips($source);
        // The break stayed a rule instead of folding into the paragraph above
        // it and becoming an em dash.
        $this->assertStringContainsString('<hr>', $this->html($this->fmt($source)));
    }

    /**
     * CONTROL. A paragraph after a paragraph keeps the `+` it always kept: this
     * is the row the condition was written for, and no mutation moves it.
     */
    public function testAnAttachedParagraphKeepsItsMarker(): void
    {
        $source = "- x\n+\ny\n";
        $this->assertSame($source, $this->fmt($source));
        $this->assertRoundTrips($source);
    }

    /**
     * CONTROL. A block that OPENS ITS OWN BLOCK at the item's content column is
     * still written indented, without a marker. A fence and a quote are the two
     * the corpus pins, which is why it never saw the defect.
     */
    public function testABlockThatOpensItsOwnBlockIsStillIndented(): void
    {
        $this->assertSame("- x\n  ```\n  c\n  ```\n", $this->fmt("- x\n+\n```\nc\n```\n"));
        $this->assertSame("- x\n  > q\n", $this->fmt("- x\n+\n> q\n"));
        $this->assertRoundTrips("- x\n+\n```\nc\n```\n");
        $this->assertRoundTrips("- x\n+\n> q\n");
    }

    /**
     * CONTROL. A definition written back in the GAP between the two blocks
     * already ended the paragraph above, so no marker is written there. This is
     * corpus 228's canonical form, and dropping the guard moves it - the
     * repository's own DefinitionWrittenBackInAnItemGapTest catches that, and
     * the row is repeated here so this file's mutation table is not blind to it.
     */
    public function testADefinitionInTheGapStillSuppressesTheMarker(): void
    {
        $source = "- see [t][r]\n  [r]: /u\n  more\n";
        $formatted = $this->fmt($source);
        $this->assertStringNotContainsString('+', $formatted);
        $this->assertRoundTrips($source);
    }

    /**
     * The sweep, as a row per construct: everything attached with `+` to an item
     * round-trips, whether the writer keeps the marker or indents the block.
     * Twenty-two constructs, three of which failed before this change.
     */
    public function testEveryAttachedConstructRoundTrips(): void
    {
        $dollar = '$';
        $constructs = [
            'paragraph' => 'y',
            'image' => '![a](i.png)',
            'figure' => "![a](i.png)\n^ cap",
            'code fence' => "```\nc\n```",
            'blockquote' => '> q',
            'heading' => '## h',
            'table' => "| a |\n|---|\n| b |",
            'thematic break' => '---',
            'frontmatter-shaped paragraph' => "---yaml\nk: v\n---",
            'div' => "::: note\nn\n:::",
            'list' => '- i',
            'ordered list' => '1. i',
            'definition list' => "t\n: d",
            'line block' => '| l',
            'math block' => $dollar . $dollar . "\nx\n" . $dollar . $dollar,
            'raw block' => "```=html\n<b>x</b>\n```",
            'comment' => "%%\nc\n%%",
            'abbreviation definition' => '*[HT]: Hyper Text',
            'link definition' => '[r]: /u',
            'footnote definition' => '[^n]: b',
            'verse fence' => "::: verse\nv\n:::",
            'admonition' => "::: warning\nw\n:::",
        ];
        $this->assertCount(22, $constructs);
        foreach ($constructs as $name => $construct) {
            $source = "- x\n+\n" . $construct . "\n";
            $this->assertSame(
                $this->html($source),
                $this->html($this->fmt($source)),
                'attached ' . $name . ' does not round-trip',
            );
        }
    }
}
