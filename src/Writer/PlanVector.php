<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use LogicException;
use Traversable;

/**
 * Fixed-width integer fields of a planned leaf.
 *
 * @implements \ArrayAccess<int, int>
 * @implements \IteratorAggregate<int, int>
 */
final class PlanVector implements ArrayAccess, Countable, IteratorAggregate
{
    private string $data = '';

    /**
     * @var int<0, max>
     */
    private int $count = 0;

    public function __construct(private string $mode)
    {
    }

    /**
     * @return int<0, max>
     */
    public function count(): int
    {
        return $this->count;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $offset >= 0 && $offset < $this->count;
    }

    public function offsetGet(mixed $offset): int
    {
        if (!$this->offsetExists($offset)) {
            throw new LogicException('Unit index outside the leaf');
        }
        $at = $offset * $this->width();
        $value = ord($this->data[$at]);
        if ($this->mode !== 'u8') {
            $value |= ord($this->data[$at + 1]) << 8;
        }
        if ($this->mode === 'i32') {
            $value |= ord($this->data[$at + 2]) << 16;
            $value |= ord($this->data[$at + 3]) << 24;
        }

        return $this->mode === 'i32' && $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset !== null) {
            throw new LogicException('Only append is supported');
        }
        $this->data .= match ($this->mode) {
            'u8' => pack('C', $value),
            'u16' => pack('v', $value),
            default => pack('V', $value),
        };
        $this->count++;
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Use pop or splice');
    }

    public function getIterator(): Traversable
    {
        for ($at = 0; $at < $this->count; $at++) {
            yield $at => $this->offsetGet($at);
        }
    }

    public function pop(): void
    {
        if ($this->count === 0) {
            return;
        }
        $this->count = max(0, $this->count - 1);
        $this->data = substr($this->data, 0, $this->count * $this->width());
    }

    public function splice(int $at): void
    {
        $start = $at * $this->width();
        $this->data = substr_replace($this->data, '', $start, $this->width());
        $this->count = max(0, $this->count - 1);
    }

    /**
     * @return array<int, int>
     */
    public function slice(int $at, int $length): array
    {
        $result = [];
        for ($end = min($this->count, $at + $length); $at < $end; $at++) {
            $result[] = $this->offsetGet($at);
        }

        return $result;
    }

    public function contains(int $code): bool
    {
        for ($at = 0; $at < $this->count; $at++) {
            if ($this->offsetGet($at) === $code) {
                return true;
            }
        }

        return false;
    }

    public function raw(int $at, int $length): string
    {
        return substr($this->data, $at * $this->width(), $length * $this->width());
    }

    private function width(): int
    {
        return match ($this->mode) {
            'u8' => 1,
            'u16' => 2,
            default => 4,
        };
    }
}
