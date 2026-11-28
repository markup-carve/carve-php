<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use LogicException;
use Traversable;

/**
 * The delimiter token list of one leaf. Fixed-width fields keep a long leaf's
 * tokens beside the source rather than one PHP array or object per delimiter.
 *
 * @implements \ArrayAccess<int, \MarkupCarve\Carve\Writer\PlanToken>
 * @implements \IteratorAggregate<int, \MarkupCarve\Carve\Writer\PlanToken>
 */
final class PlanTokens implements ArrayAccess, Countable, IteratorAggregate
{
    private string $data = '';

    private string $pairs = '';

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\PlanToken>
     */
    private array $changed = [];

    /**
     * @var int<0, max>
     */
    private int $count = 0;

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

    public function offsetGet(mixed $offset): PlanToken
    {
        if (!$this->offsetExists($offset)) {
            throw new LogicException('Token index outside the leaf');
        }
        if (isset($this->changed[$offset])) {
            return clone $this->changed[$offset];
        }
        $at = $offset * 20;
        $token = new PlanToken(
            self::signed($this->word($this->data, $at)),
            self::signed($this->word($this->data, $at + 4)),
            self::signed($this->word($this->data, $at + 8)),
            self::signed($this->word($this->data, $at + 12)),
            self::signed($this->word($this->data, $at + 16)),
        );
        $token->pair = self::signed($this->word($this->pairs, $offset * 4));

        return $token;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data .= pack('V5', $value->kind, $value->start, $value->end, $value->mark, $value->flags);
            $this->pairs .= pack('V', $value->pair);
            $this->count++;

            return;
        }
        if (!$this->offsetExists($offset)) {
            throw new LogicException('Token index outside the leaf');
        }
        $packed = pack('V', $value->pair);
        $at = $offset * 4;
        for ($byte = 0; $byte < 4; $byte++) {
            $this->pairs[$at + $byte] = $packed[$byte];
        }
        $original = $offset * 20;
        if (
            $value->kind !== self::signed($this->word($this->data, $original))
            || $value->start !== self::signed($this->word($this->data, $original + 4))
            || $value->end !== self::signed($this->word($this->data, $original + 8))
            || $value->mark !== self::signed($this->word($this->data, $original + 12))
            || $value->flags !== self::signed($this->word($this->data, $original + 16))
        ) {
            $this->changed[$offset] = clone $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Plan tokens are not removed');
    }

    public function getIterator(): Traversable
    {
        for ($at = 0; $at < $this->count; $at++) {
            yield $at => $this->offsetGet($at);
        }
    }

    private static function signed(int $value): int
    {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    private function word(string $source, int $at): int
    {
        return ord($source[$at]) | (ord($source[$at + 1]) << 8)
        | (ord($source[$at + 2]) << 16) | (ord($source[$at + 3]) << 24);
    }
}
