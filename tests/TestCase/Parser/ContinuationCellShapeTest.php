<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A cell rebuilt from `+` continuation rows is ONE text node (carve-php#612).
 *
 * The node SHAPE is the contract, not just whether positions are present:
 * PART 12 says a consumer written against one implementation must be able to
 * read another's output, and carve-js emits a single node here.
 *
 * This exists because span-correctness testing could not catch the regression
 * that made it three. Every one of those three spans was correct - sliced back
 * out of the source it matched its value exactly - so a check that verifies
 * spans reports perfect health on a structurally divergent tree. Three healthy
 * spans read exactly like one.
 */
class ContinuationCellShapeTest extends TestCase
{
    /**
     * @return list<\MarkupCarve\Carve\Node\Inline\Text>
     */
    protected function cellTexts(Node $node): array
    {
        $found = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $found[] = $child;
            }
            $found = array_merge($found, $this->cellTexts($child));
        }

        return $found;
    }

    protected function parseWithPositions(string $carve): Node
    {
        // Positions are opt-in - the same construction `bin/carve --json` uses.
        return (new CarveConverter(parser: new BlockParser(false, false, false, true)))->parse($carve);
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Block\TableCell>
     */
    protected function cells(Node $node): array
    {
        $found = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableCell) {
                $found[] = $child;
            }
            $found = array_merge($found, $this->cells($child));
        }

        return $found;
    }

    public function testAContinuationCellIsASingleTextNode(): void
    {
        $carve = "|= Feature |= Description        |\n"
            . "| Complex  | A long description |\n"
            . "+          | that continues     |\n"
            . "+          | across lines.      |\n";

        $cells = $this->cells($this->parseWithPositions($carve));
        $rebuilt = $this->cellTexts($cells[3]);

        $this->assertCount(1, $rebuilt, 'a rebuilt cell must not be split into one node per source chunk');
        $this->assertSame('A long description that continues across lines.', $rebuilt[0]->getContent());
    }

    /**
     * And it is deliberately UNPLACED.
     *
     * Its content is not a contiguous slice of the source - the `+ |` markers
     * sit between the pieces - and this repo requires a text span to slice back
     * to its value exactly (SourcePositionTest). A span over the whole region
     * would break that rule; one span per chunk would break the node shape.
     * carve-js leaves it unplaced for the same reason, so this is parity rather
     * than a gap.
     *
     * Inline nodes that land on an authored chunk are still placed; only the
     * all-plain rebuilt text is not.
     */
    public function testTheRebuiltCellIsDeliberatelyUnplaced(): void
    {
        $carve = "|= Feature |= Description        |\n"
            . "| Complex  | A long description |\n"
            . "+          | that continues     |\n"
            . "+          | across lines.      |\n";

        $cells = $this->cells($this->parseWithPositions($carve));
        $rebuilt = $this->cellTexts($cells[3]);

        $this->assertNull(
            $rebuilt[0]->getPos(),
            'a value assembled from non-adjacent source has no span it can slice back to',
        );
    }

    public function testASingleLineCellIsUnchanged(): void
    {
        $carve = "|= A |= B |\n| one | two |\n";

        $cells = $this->cells($this->parseWithPositions($carve));

        foreach ($cells as $cell) {
            $this->assertCount(1, $this->cellTexts($cell));
        }
    }
}
