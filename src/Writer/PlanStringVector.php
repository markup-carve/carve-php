<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use LogicException;
use Traversable;

/**
 * Interned node paths and types for the parallel code-unit fields of a leaf.
 *
 * @implements \ArrayAccess<int, string>
 * @implements \IteratorAggregate<int, string>
 */
final class PlanStringVector implements ArrayAccess, Countable, IteratorAggregate
{
    private PlanVector $ids;

    /**
     * @var array<string, int>
     */
    private array $names = [];

    /**
     * @var array<int, string>
     */
    private array $values = [0 => ''];

    public function __construct()
    {
        $this->ids = new PlanVector('i32');
    }

    /**
     * @return int<0, max>
     */
    public function count(): int
    {
        return count($this->ids);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->ids->offsetExists($offset);
    }

    public function offsetGet(mixed $offset): string
    {
        return $this->values[$this->ids[$offset]];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset !== null) {
            throw new LogicException('Expected appended string unit');
        }
        $id = $this->names[$value] ?? null;
        if ($id === null) {
            $id = count($this->values);
            $this->names[$value] = $id;
            $this->values[$id] = $value;
        }
        $this->ids[] = $id;
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Use pop or splice');
    }

    public function getIterator(): Traversable
    {
        for ($at = 0, $count = count($this->ids); $at < $count; $at++) {
            yield $at => $this->offsetGet($at);
        }
    }

    public function pop(): void
    {
        $this->ids->pop();
    }

    public function splice(int $at): void
    {
        $this->ids->splice($at);
    }
}
