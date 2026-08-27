<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A caption that wraps across lines keeps the positions of its inlines, and the
 * host it attaches to still reaches the end of it.
 *
 * The caption arms located their text by searching for it in ONE source line -
 * the `^ ` line. A wrapped caption is a string the block layer BUILT by joining
 * the continuation lines with "\n", so it is not a run of any source line, the
 * search declined, and the whole caption parsed with no map at all: every
 * inline came back unplaced, and the container extent that derives a figure's
 * span from its children then had nothing past the image to reach
 * (carve-php#1819).
 *
 * A SINGLE-line caption was correct throughout, which is why nothing saw this:
 * the rendered HTML is byte-identical to carve-js and carve-rs either way, so
 * only the three-way AST span comparison in the spec repo could find it, and
 * only on the one corpus document that wraps a caption.
 *
 * The five hosts PART 9 §4 gives a caption all reached the text through the
 * same helper, so all five were affected and all five are pinned here.
 */
class ASoftWrappedCaptionKeepsItsPositionsTest extends TestCase
{
    /**
     * @param string $source
     *
     * @return array<string, mixed>
     */
    private function encode(string $source): array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));

        return (new AstCodec())->encode($converter->parse($source));
    }

    /**
     * The caption's inlines, as `type` plus start/end offset (null when unplaced).
     *
     * @param array<string, mixed> $host
     *
     * @return array<array{string, int|null, int|null}>
     */
    private function captionOffsets(array $host): array
    {
        $offsets = [];
        /** @var array<array<string, mixed>> $caption */
        $caption = $host['caption'] ?? [];
        foreach ($caption as $inline) {
            /** @var array<string, int>|null $pos */
            $pos = $inline['pos'] ?? null;
            $offsets[] = [(string)$inline['type'], $pos['startOffset'] ?? null, $pos['endOffset'] ?? null];
        }

        return $offsets;
    }

    /**
     * The offsets carve-js and carve-rs both report for `^ cap one` / `continued`
     * once the caption text starts at `$captionStart`.
     *
     * @param int $captionStart
     *
     * @return array<array{string, int|null, int|null}>
     */
    private function wrappedInlines(int $captionStart): array
    {
        return [
            ['text', $captionStart, $captionStart + 7],
            ['soft_break', $captionStart + 7, $captionStart + 8],
            ['text', $captionStart + 8, $captionStart + 17],
        ];
    }

    public function testAnImageFigureReachesTheEndOfItsWrappedCaption(): void
    {
        $figure = $this->encode("![a](/u)\n^ cap one\ncontinued\n")['children'][0];

        $this->assertSame('figure', $figure['type']);
        $this->assertSame(0, $figure['pos']['startOffset']);
        $this->assertSame(28, $figure['pos']['endOffset']);
        $this->assertSame(3, $figure['pos']['endLine']);
        $this->assertSame($this->wrappedInlines(11), $this->captionOffsets($figure));
    }

    public function testATableReachesTheEndOfItsWrappedCaption(): void
    {
        $table = $this->encode("| a |\n|---|\n| b |\n^ cap one\ncontinued\n")['children'][0];

        $this->assertSame('table', $table['type']);
        $this->assertSame(0, $table['pos']['startOffset']);
        $this->assertSame(37, $table['pos']['endOffset']);
        $this->assertSame($this->wrappedInlines(20), $this->captionOffsets($table));
    }

    public function testACodeBlockFigureReachesTheEndOfItsWrappedCaption(): void
    {
        $figure = $this->encode("```\nx\n```\n^ cap one\ncontinued\n")['children'][0];

        $this->assertSame('figure', $figure['type']);
        $this->assertSame(29, $figure['pos']['endOffset']);
        $this->assertSame($this->wrappedInlines(12), $this->captionOffsets($figure));
    }

    public function testABlockQuoteFigureReachesTheEndOfItsWrappedCaption(): void
    {
        $figure = $this->encode("> q\n^ cap one\ncontinued\n")['children'][0];

        $this->assertSame('figure', $figure['type']);
        $this->assertSame(23, $figure['pos']['endOffset']);
        $this->assertSame($this->wrappedInlines(6), $this->captionOffsets($figure));
    }

    public function testADisplayMathFigureReachesTheEndOfItsWrappedCaption(): void
    {
        $figure = $this->encode("$$`x`\n^ cap one\ncontinued\n")['children'][0];

        $this->assertSame('figure', $figure['type']);
        $this->assertSame(25, $figure['pos']['endOffset']);
        $this->assertSame($this->wrappedInlines(8), $this->captionOffsets($figure));
    }

    public function testAFigureGroupReachesTheEndOfItsWrappedCaption(): void
    {
        $group = $this->encode("::: figure\n![a](/u)\n:::\n^ cap one\ncontinued\n")['children'][0];

        $this->assertSame('figure_group', $group['type']);
        $this->assertSame(0, $group['pos']['startOffset']);
        $this->assertSame(43, $group['pos']['endOffset']);
        $this->assertSame($this->wrappedInlines(26), $this->captionOffsets($group));
    }

    /**
     * Caption text that REPEATS ITS OWN MARKER is anchored past the marker.
     *
     * The search for a caption's text in its source line started at column 0,
     * so `^ ^` found the marker rather than the content and reported a span one
     * construct too far left. Nothing could catch it: the guard that verifies a
     * span slices the source back and compares it to the node's value, and both
     * readings slice the same byte.
     *
     * This half was wrong for a SINGLE-line caption too, before and
     * independently of the wrap - carve-php read `text` at 9..10 where carve-js
     * and carve-rs read 11..12 - so it is pinned in both forms.
     */
    public function testCaptionTextThatRepeatsItsOwnMarkerIsAnchoredPastIt(): void
    {
        $wrapped = $this->encode("![a](/u)\n^ ^\ncontinued\n")['children'][0];

        $this->assertSame(22, $wrapped['pos']['endOffset']);
        $this->assertSame(
            [['text', 11, 12], ['soft_break', 12, 13], ['text', 13, 22]],
            $this->captionOffsets($wrapped),
        );

        $single = $this->encode("![a](/u)\n^ ^\n")['children'][0];

        $this->assertSame(12, $single['pos']['endOffset']);
        $this->assertSame([['text', 11, 12]], $this->captionOffsets($single));
    }

    /**
     * A wrapped caption inside a container is placed against the RAW lines.
     *
     * The collector holds lines the quote prefix was already stripped from, so
     * a column measured against those would be short by the prefix. The marker
     * width stays a valid lower bound for the search because a prefix only
     * pushes the content further right - which is what keeps `> ^ >` anchored
     * at the caption's caret and not at the quote's.
     */
    public function testAWrappedCaptionInsideABlockQuoteIsPlaced(): void
    {
        $quote = $this->encode("> ![a](/u)\n> ^ >\n> continued\n")['children'][0];
        $figure = $quote['children'][0];

        $this->assertSame('figure', $figure['type']);
        $this->assertSame(2, $figure['pos']['startOffset']);
        $this->assertSame(28, $figure['pos']['endOffset']);
        $this->assertSame(
            [['text', 15, 16], ['soft_break', 16, 19], ['text', 19, 28]],
            $this->captionOffsets($figure),
        );
    }

    /**
     * The control: a caption on ONE line was always placed, and stays placed.
     */
    public function testASingleLineCaptionIsUnchanged(): void
    {
        $figure = $this->encode("![a](/u)\n^ cap one\n")['children'][0];

        $this->assertSame(0, $figure['pos']['startOffset']);
        $this->assertSame(18, $figure['pos']['endOffset']);
        $this->assertSame([['text', 11, 18]], $this->captionOffsets($figure));
    }
}
