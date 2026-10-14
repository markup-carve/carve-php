<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class ATableRowEndsBeforeItsSpannedContinuationTest extends TestCase
{
    public function testRowsKeepTheirOwnExtents(): void
    {
        $source = "| a | b |\n| ^ | c |\n+ more | d |\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $rows = $document->getChildren()[0]->getChildren();

        self::assertSame([0, 9], [$rows[0]->getPos()?->startOffset, $rows[0]->getPos()?->endOffset]);
        self::assertSame([10, 32], [$rows[1]->getPos()?->startOffset, $rows[1]->getPos()?->endOffset]);

        $texts = [];
        $pending = [$rows[0]];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node->getType() === 'text') {
                $texts[$node->getContent()] = $node->getPos();
            }
            array_push($pending, ...$node->getChildren());
        }
        self::assertArrayHasKey('more', $texts);
        self::assertNull($texts['more']);
    }
}
