<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Bench;

use MarkupCarve\Carve\Block\Frame;
use MarkupCarve\Carve\Block\NodeFactory;
use MarkupCarve\Carve\Block\Sink;
use MarkupCarve\Carve\Node;

/**
 * Event encodings measured against the flat integer list of EventSink (see _DESIGN.md):
 * arrays of arrays, and one object per event.
 */
final class ArrayEventSink implements Sink
{
    /** @var array<array{0: int, 1: int, 2: int}> */
    public array $events = [];

    /** @var array<\MarkupCarve\Carve\Block\Frame> */
    public array $payload = [];

    public function enter(Frame $frame, Frame $parent): void
    {
        $this->payload[] = $frame;
        $this->events[] = [1, $frame->start, count($this->payload) - 1];
    }

    public function leaf(Frame $frame, Frame $parent): void
    {
        $this->payload[] = $frame;
        $this->events[] = [2, $frame->start, count($this->payload) - 1];
        $spans = $frame->spans;
        $n = count($spans);
        for ($k = 0; $k < $n; $k += 2) {
            if ($spans[$k] === -1) {
                $this->events[] = [3, -1, -1];
                $k--;

                continue;
            }
            $this->events[] = [3, $spans[$k], $spans[$k + 1]];
        }
    }

    /**
     * @inheritDoc
     */
    public function caption(Frame $caption, Frame $parent, Frame $target): void
    {
        // The bench shapes measure event volume; the figure wrap is not replayed.
    }

    public function exit(Frame $frame): void
    {
        $this->events[] = [4, $frame->end, 0];
    }

    public function build(string $s): Node
    {
        $stack = [];
        $root = null;
        $events = $this->events;
        $n = count($events);
        for ($k = 0; $k < $n; $k++) {
            [$kind, , $third] = $events[$k];
            if ($kind === 1) {
                $frame = $this->payload[$third];
                $node = NodeFactory::make($frame, $s);
                if ($stack !== []) {
                    $stack[count($stack) - 1][0]->children[] = $node;
                } else {
                    $root = $node;
                }
                $stack[] = [$node, $frame];
            } elseif ($kind === 2) {
                $frame = $this->payload[$third];
                $spans = [];
                while ($k + 1 < $n && $events[$k + 1][0] === 3) {
                    $k++;
                    if ($events[$k][1] === -1) {
                        $spans[] = -1;

                        continue;
                    }
                    $spans[] = $events[$k][1];
                    $spans[] = $events[$k][2];
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

final class Event
{
    public function __construct(public int $kind, public int $start, public int $end, public ?Frame $frame = null)
    {
    }
}

final class ObjectEventSink implements Sink
{
    /** @var array<\MarkupCarve\Carve\Bench\Event> */
    public array $events = [];

    public function enter(Frame $frame, Frame $parent): void
    {
        $this->events[] = new Event(1, $frame->start, -1, $frame);
    }

    public function leaf(Frame $frame, Frame $parent): void
    {
        $this->events[] = new Event(2, $frame->start, $frame->end, $frame);
        $spans = $frame->spans;
        $n = count($spans);
        for ($k = 0; $k < $n; $k += 2) {
            if ($spans[$k] === -1) {
                $this->events[] = new Event(3, -1, -1);
                $k--;

                continue;
            }
            $this->events[] = new Event(3, $spans[$k], $spans[$k + 1]);
        }
    }

    /**
     * @inheritDoc
     */
    public function caption(Frame $caption, Frame $parent, Frame $target): void
    {
        // The bench shapes measure event volume; the figure wrap is not replayed.
    }

    public function exit(Frame $frame): void
    {
        $this->events[] = new Event(4, $frame->end, 0);
    }

    public function build(string $s): Node
    {
        $stack = [];
        $root = null;
        $events = $this->events;
        $n = count($events);
        for ($k = 0; $k < $n; $k++) {
            $e = $events[$k];
            if ($e->kind === 1) {
                assert($e->frame !== null);
                $node = NodeFactory::make($e->frame, $s);
                if ($stack !== []) {
                    $stack[count($stack) - 1][0]->children[] = $node;
                } else {
                    $root = $node;
                }
                $stack[] = [$node, $e->frame];
            } elseif ($e->kind === 2) {
                assert($e->frame !== null);
                $spans = [];
                while ($k + 1 < $n && $events[$k + 1]->kind === 3) {
                    $k++;
                    if ($events[$k]->start === -1) {
                        $spans[] = -1;

                        continue;
                    }
                    $spans[] = $events[$k]->start;
                    $spans[] = $events[$k]->end;
                }
                $stack[count($stack) - 1][0]->children[] = NodeFactory::make($e->frame, $s);
            } else {
                [$node, $frame] = array_pop($stack);
                NodeFactory::finish($node, $frame);
            }
        }
        assert($root instanceof Node);

        return $root;
    }
}
