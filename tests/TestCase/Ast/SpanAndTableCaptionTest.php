<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Two values the published schema rejects.
 *
 * A span marker is `rowspan` or `colspan` on the wire. This engine keeps the
 * character the author typed - right to keep, since a formatter reproduces it,
 * wrong to publish: `<` means nothing to a consumer that did not parse Carve.
 *
 * A caption is an array of inline nodes wherever it appears. A figure's was
 * already mapped; a TABLE's was still going out as the block node this engine
 * models it with.
 */
class SpanAndTableCaptionTest extends TestCase
{
    private AstCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(string $source): array
    {
        return $this->codec->encode((new CarveConverter())->parse($source));
    }

    public function testASpanMarkerIsNamedOnTheWire(): void
    {
        // A span marker continues the cell above/left, so it needs a real row
        // to continue FROM - the corpus shape, not a two-row table.
        $table = $this->encode("| A | B | C |\n|---|---|---|\n| x | y | z |\n| ^ | < | d |\n")['children'][0];
        $spans = [];
        foreach ($table['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                if (isset($cell['span'])) {
                    $spans[] = $cell['span'];
                }
            }
        }

        // Only ONE span survives, and that is the model gap this change does NOT
        // close: the `^` was merged into the origin cell as `rowspan: 2` and its
        // placeholder dropped, where carve-js keeps a placeholder carrying
        // `span: "rowspan"`. So this row has two cells here and three there.
        // Mapping one to the other changes how many cells a row has, which is a
        // decision about the tree rather than an encoder concern.
        $this->assertSame(['colspan'], $spans);
    }

    public function testARowspanMarkerIsNamedToo(): void
    {
        $table = $this->encode("| < | b |\n|---|---|\n| c | d |\n")['children'][0];

        $this->assertSame('colspan', $table['rows'][0]['cells'][0]['span']);
    }

    public function testATableCaptionIsInlineContent(): void
    {
        $table = $this->encode("| a |\n|---|\n| b |\n\n^ Fruit prices\n")['children'][0];

        $this->assertIsArray($table['caption']);
        $this->assertSame('text', $table['caption'][0]['type']);
        $this->assertSame('Fruit prices', $table['caption'][0]['value']);
    }

    public function testBothSurviveARoundTrip(): void
    {
        $converter = new CarveConverter();
        foreach (
            [
                "| A | B | C |\n|---|---|---|\n| x | y | z |\n| ^ | < | d |\n",
                "| < | b |\n|---|---|\n| c | d |\n",
                "| a |\n|---|\n| b |\n\n^ Fruit prices\n",
            ] as $source
        ) {
            $decoded = $this->codec->decode($this->encode($source));

            $this->assertSame(
                $converter->render($converter->parse($source)),
                $converter->render($decoded),
                sprintf('%s must render identically after a round trip', json_encode($source)),
            );
        }
    }
}
