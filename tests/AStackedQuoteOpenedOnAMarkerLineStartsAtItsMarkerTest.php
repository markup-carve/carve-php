<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §4: a quote opened on an item's marker line starts at its `>`, also when
 * its `+` nests a quote follower inside it (corpus 654, carve-php#3754).
 */
class AStackedQuoteOpenedOnAMarkerLineStartsAtItsMarkerTest extends TestCase
{
    use RuleATodo;

    /**
     * @return iterable<string, array{string, array<string>}>
     */
    public static function sources(): iterable
    {
        yield 'two stacked quotes' => ["- > > +\n> q\n", ['1:3 [2,11)', '1:5 [4,11)', '2:1 [8,11)']];
        yield 'three stacked quotes' => ["- > > > +\n> q\n", ['1:3 [2,13)', '1:5 [4,13)', '1:7 [6,13)', '2:1 [10,13)']];
        yield 'in a nested item' => ["- a\n  - > > +\n      > q\n", ['2:5 [8,23)', '2:7 [10,23)', '3:7 [20,23)']];
        yield 'in a host quote' => ["> - > > +\n> > q\n", ['1:1 [0,15)', '1:5 [4,15)', '1:7 [6,15)', '2:3 [12,15)']];
    }

    /**
     * @param string $source
     * @param array<string> $expected
     */
    #[DataProvider('sources')]
    public function testEveryQuoteStartsAtItsMarker(string $source, array $expected): void
    {
        $spans = [];
        $pending = [(new BlockParser(trackPositions: true))->parse($source)];
        while ($pending !== []) {
            $node = array_shift($pending);
            $position = $node->getPos();
            if ($node->getType() === 'block_quote' && $position !== null) {
                $spans[] = sprintf('%d:%d [%d,%d)', $position->startLine, $position->startColumn, $position->startOffset, $position->endOffset);
            }
            array_unshift($pending, ...$node->getChildren());
        }

        $this->assertSame($expected, $spans);
    }
}
