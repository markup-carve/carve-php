<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Test\Support\Linearity;
use MarkupCarve\Carve\Writer\Blocks;
use MarkupCarve\Carve\Writer\State;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DescendantFenceRunLinearityTest extends TestCase
{
    public function testAncestorFenceWidthsReuseDescendantScan(): void
    {
        self::assertSame(65, self::scan(64));
        self::assertSame(257, self::scan(256));

        $excess = Linearity::excess(
            static fn () => self::scan(64),
            static fn () => self::scan(256),
            4,
        );

        self::assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    private static function scan(int $depth): int
    {
        $tree = ['type' => 'text', 'value' => 'x'];
        for ($i = 0; $i < $depth; $i++) {
            $tree = ['type' => 'div', 'children' => [$tree]];
        }

        $blocks = new Blocks(new State([], false, false));
        $scan = new ReflectionMethod(Blocks::class, 'runsBelow');
        $path = '/children/0';
        $node = $tree;
        $root = 0;
        for ($i = 0; $i < $depth; $i++) {
            $longest = $scan->invoke($blocks, $node, $path);
            if ($i === 0) {
                $root = $longest;
            }
            $node = $node['children'][0];
            $path .= '/children/0';
        }

        return $root;
    }
}
