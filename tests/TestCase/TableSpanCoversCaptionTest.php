<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A table's span covers its caption, because the caption is one of its
 * children.
 *
 * The caption line is written after the last row and attached to the table
 * afterwards, so the table's span stopped at the rows and the caption's inlines
 * sat outside their own parent. carve-js covers both; carve-rs has the same
 * defect (carve#565).
 *
 * Nothing rendered differently: a span is compared against source text for text
 * nodes alone, so a block's span is checked for being present and in range and
 * never for containing what the block contains.
 */
class TableSpanCoversCaptionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function encode(string $source): array
    {
        $parser = new BlockParser(trackPositions: true);

        return (new AstCodec())->encode($parser->parse($source));
    }

    public function testTheTableSpanReachesTheEndOfItsCaption(): void
    {
        $encoded = $this->encode("| a |\n^ cap\n");
        $table = $encoded['children'][0];

        $this->assertSame('table', $table['type']);
        $captionEnd = $table['caption'][0]['pos']['endOffset'];
        $this->assertSame($captionEnd, $table['pos']['endOffset']);
    }

    public function testEveryCaptionInlineIsInsideTheTableSpan(): void
    {
        $encoded = $this->encode("|= H |\n| a |\n^ a *bold* caption\n");
        $table = $encoded['children'][0];

        foreach ($table['caption'] as $inline) {
            $this->assertGreaterThanOrEqual($table['pos']['startOffset'], $inline['pos']['startOffset']);
            $this->assertLessThanOrEqual($table['pos']['endOffset'], $inline['pos']['endOffset']);
        }
    }

    public function testATableWithoutACaptionIsUnchanged(): void
    {
        $encoded = $this->encode("| a |\n");
        $table = $encoded['children'][0];

        $this->assertSame(0, $table['pos']['startOffset']);
        $this->assertSame(5, $table['pos']['endOffset']);
    }
}
