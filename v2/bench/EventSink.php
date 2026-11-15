<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Bench;

use MarkupCarve\Carve2\Block\Frame;
use MarkupCarve\Carve2\Block\NodeFactory;
use MarkupCarve\Carve2\Block\Sink;

/**
 * The event shape: the block pass appends events; a builder makes the tree from them.
 * Encoding: a flat integer list of (kind, start, end) triples. The payload of an opener is
 * kept in a side list indexed by the third slot of its ENTER or LEAF event.
 */
final class EventSink implements Sink
{
    public const ENTER = 1;
    public const LEAF = 2;
    public const LINE = 3;
    public const EXIT = 4;

    /**
     * @var array<int>
     */
    public array $events = [];

    /**
     * @var array<\MarkupCarve\Carve2\Block\Frame>
     */
    public array $payload = [];

    /**
     * @inheritDoc
     */
    public function enter(Frame $frame, Frame $parent): void
    {
        $this->payload[] = $frame;
        array_push($this->events, self::ENTER, $frame->start, count($this->payload) - 1);
    }

    /**
     * @inheritDoc
     */
    public function leaf(Frame $frame, Frame $parent): void
    {
        $this->payload[] = $frame;
        array_push($this->events, self::LEAF, $frame->start, count($this->payload) - 1);
        $spans = $frame->spans;
        $n = count($spans);
        for ($k = 0; $k < $n; $k += 2) {
            if ($spans[$k] === -1) {
                array_push($this->events, self::LINE, -1, -1);
                $k--;

                continue;
            }
            array_push($this->events, self::LINE, $spans[$k], $spans[$k + 1]);
        }
    }

    /**
     * @inheritDoc
     */
    public function exit(Frame $frame): void
    {
        array_push($this->events, self::EXIT, $frame->end, 0);
    }
}
