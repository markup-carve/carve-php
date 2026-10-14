<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class ALineBlockBreakUsesItsPhysicalLineTest extends TestCase
{
    public function testBreakAfterListBlankUsesPhysicalSourceCoordinates(): void
    {
        $source = "- x\n\n  ::: |\n  ab\\\t\n  cd\n  :::\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $pending = [$document];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node->getType() === 'hard_break') {
                $position = $node->getPos();
                self::assertNotNull($position);
                self::assertSame([4, 5, 5, 3, 17, 22], [
                    $position->startLine,
                    $position->endLine,
                    $position->startColumn,
                    $position->endColumn,
                    $position->startOffset,
                    $position->endOffset,
                ]);

                return;
            }
            array_push($pending, ...$node->getChildren());
        }
        self::fail('Expected a hard break in the line block.');
    }
}
