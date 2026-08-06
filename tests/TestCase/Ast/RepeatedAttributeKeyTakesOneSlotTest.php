<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A slot appears once in `attrs.order`, at its first appearance.
 *
 * `order` is the "source-appearance order of the slots" and `keyValues` holds
 * one entry per key, so a key listed twice makes the two disagree about how
 * many slots the source had - and a formatter walking `order` to rebuild the
 * block emits the key twice from a document that has one (carve-php#878).
 *
 * The dedup guard here covered `#id` and `.class` and not ordinary keys, which
 * is why an id or a class written twice was already correct.
 *
 * The LAST value still wins - that half was never in question - so only the
 * slot list changes.
 */
class RepeatedAttributeKeyTakesOneSlotTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function attrs(string $source): array
    {
        $tree = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'paragraph' && isset($node['attrs'])) {
                $found = $found ?: $node['attrs'];
            }
            foreach ($node as $value) {
                $walk($value);
            }
        };
        $walk($tree);

        return $found;
    }

    public function testARepeatedKeyTakesOneSlotAtItsFirstAppearance(): void
    {
        $attrs = $this->attrs("{#id}\n{key=val}\n{.foo .bar}\n{key=val2}\n{.baz}\n{#id2}\nOkay\n");

        $this->assertSame(['#id', 'key', '.class'], $attrs['order'] ?? null);
    }

    public function testTheLastValueStillWins(): void
    {
        // Unchanged, and asserted here so a pass on the slot list cannot hide a
        // regression in the value.
        $attrs = $this->attrs("{#id}\n{key=val}\n{.foo .bar}\n{key=val2}\n{.baz}\n{#id2}\nOkay\n");

        $this->assertSame(['key' => 'val2'], $attrs['keyValues'] ?? null);
        $this->assertSame('id2', $attrs['id'] ?? null);
        $this->assertSame(['foo', 'bar', 'baz'], $attrs['classes'] ?? null);
    }

    public function testARepeatedIdOrClassWasAlreadyCorrect(): void
    {
        // The guard that existed. If the fix had replaced it rather than
        // widened it, this would start listing `#id` twice.
        $attrs = $this->attrs("{#a}\n{#b}\n{.x}\n{.y}\nOkay\n");

        $this->assertSame(['#id', '.class'], $attrs['order'] ?? null);
    }

    public function testTwoDistinctKeysKeepBothSlots(): void
    {
        // The control against over-deduping: different keys are different slots.
        $attrs = $this->attrs("{a=1}\n{b=2}\nOkay\n");

        $this->assertSame(['a', 'b'], $attrs['order'] ?? null);
    }
}
