<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class AQuotedListSpanStartsAtItsMarkerTest extends TestCase
{
    public function testEveryQuotedListAndItemStartsAtOpeningMarkup(): void
    {
        foreach ([4, 5] as $row) {
            $source = file_get_contents(__DIR__ . '/spec/tests/corpus/556-a-closed-block-leaves-no-frame-for-the-line-below-' . $row . '.crv');
            $pending = [(new BlockParser(trackPositions: true))->parse($source)];
            $count = 0;
            while ($pending !== []) {
                $node = array_pop($pending);
                if (in_array($node->getType(), ['list', 'list_item'], true)) {
                    $position = $node->getPos();
                    self::assertNotNull($position);
                    self::assertMatchesRegularExpression('/^[-1]/', substr($source, $position->startOffset));
                    $count++;
                }
                array_push($pending, ...$node->getChildren());
            }
            self::assertSame(8, $count);
        }
    }
}
