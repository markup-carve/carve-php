<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class AQuotedLineBlockBreakIncludesTheNextPrefixTest extends TestCase
{
    public function testBreakEndsWhereTheNextPayloadStarts(): void
    {
        foreach (["> ::: |\n> # h\n> x\nafter\n", "> ::: |\r\n> # h\r\n> x\r\nafter\r\n"] as $source) {
            $pending = [(new BlockParser(trackPositions: true))->parse($source)];
            $found = false;
            while ($pending !== []) {
                $node = array_pop($pending);
                if ($node->getType() === 'hard_break' && $node->getPos()?->startLine === 2) {
                    $position = $node->getPos();
                    self::assertSame(strpos($source, 'x'), $position->endOffset);
                    self::assertSame(3, $position->endLine);
                    self::assertSame(3, $position->endColumn);
                    $found = true;
                }
                array_push($pending, ...$node->getChildren());
            }
            self::assertTrue($found);
        }
    }
}
