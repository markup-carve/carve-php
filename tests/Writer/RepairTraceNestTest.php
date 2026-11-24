<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\RepairTrace;
use PHPUnit\Framework\TestCase;

final class RepairTraceNestTest extends TestCase
{
    /**
     * Nodes are traced inside out; each node path keeps the form `packPath()` gives it, also where a path repeats
     * `children` or holds the packed byte.
     */
    public function testNodesInsideOutHoldPackPathForms(): void
    {
        $paths = [];
        foreach (['/children/0', '/children/children/1', "/children/\x01/children/0", "\x02/children/2"] as $root) {
            $deep = [];
            $path = $root;
            for ($depth = 0; $depth < 40; $depth++) {
                $path .= '/children/' . ($depth % 3);
                $deep[] = $path;
            }
            array_push($paths, ...array_reverse($deep));
            $paths[] = $root . '/children/7';
        }
        $trace = new RepairTrace([], true, "\x01m\x03");
        $marked = '';
        foreach ($paths as $path) {
            $marked .= $trace->inlineNode('x', $path, 'span');
        }
        $nodes = array_values($trace->decode($marked)['nodes']);

        self::assertSame(array_map(RepairTrace::packPath(...), $paths), array_column($nodes, 0));
    }
}
