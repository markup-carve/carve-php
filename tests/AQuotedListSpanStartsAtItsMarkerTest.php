<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class AQuotedListSpanStartsAtItsMarkerTest extends TestCase
{
    use RuleATodo;

    public function testEveryQuotedListAndItemStartsAtOpeningMarkup(): void
    {
        // A container span may start at its parent's content column (ast-json-contract).
        $expected = [4 => [36, 36, 2, 10, 18, 18, 2], 5 => [32, 32, 2, 10, 18, 18, 2]];
        foreach ([4, 5] as $row) {
            $source = file_get_contents(__DIR__ . '/spec/tests/corpus/556-a-closed-block-leaves-no-frame-for-the-line-below-' . $row . '.crv');
            $pending = [(new BlockParser(trackPositions: true))->parse($source)];
            $count = 0;
            $starts = [];
            while ($pending !== []) {
                $node = array_pop($pending);
                if (in_array($node->getType(), ['list', 'list_item'], true)) {
                    $position = $node->getPos();
                    self::assertNotNull($position);
                    $starts[] = $position->startOffset;
                    $count++;
                }
                array_push($pending, ...$node->getChildren());
            }
            self::assertSame(7, $count);
            self::assertSame($expected[$row], $starts);
        }
    }
}
