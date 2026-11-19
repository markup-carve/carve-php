<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Bench;

use MarkupCarve\Carve\Block\Frame;
use MarkupCarve\Carve\Block\NodeFactory;
use MarkupCarve\Carve\Block\Sink;

use MarkupCarve\Carve\Node;

/**
 * Builds the tree from the events of an EventSink.
 */
final class EventTreeBuilder
{
    /**
     * @param \MarkupCarve\Carve\Block\EventSink $sink
     * @param string $s
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function build(EventSink $sink, string $s): Node
    {
        $events = $sink->events;
        $payload = $sink->payload;
        $n = count($events);
        /** @var array<array{0: \MarkupCarve\Carve\Node, 1: \MarkupCarve\Carve\Block\Frame}> $stack */
        $stack = [];
        $root = null;
        for ($k = 0; $k < $n; $k += 3) {
            $kind = $events[$k];
            if ($kind === EventSink::ENTER) {
                $frame = $payload[$events[$k + 2]];
                $node = NodeFactory::make($frame, $s);
                if ($stack !== []) {
                    $stack[count($stack) - 1][0]->children[] = $node;
                } else {
                    $root = $node;
                }
                $stack[] = [$node, $frame];
            } elseif ($kind === EventSink::LEAF) {
                $frame = $payload[$events[$k + 2]];
                $spans = [];
                while ($k + 3 < $n && $events[$k + 3] === EventSink::LINE) {
                    $k += 3;
                    if ($events[$k + 1] === -1) {
                        $spans[] = -1;

                        continue;
                    }
                    $spans[] = $events[$k + 1];
                    $spans[] = $events[$k + 2];
                }
                $stack[count($stack) - 1][0]->children[] = NodeFactory::make($frame, $s);
            } else {
                [$node, $frame] = array_pop($stack);
                NodeFactory::finish($node, $frame);
            }
        }
        assert($root instanceof Node);

        return $root;
    }
}
