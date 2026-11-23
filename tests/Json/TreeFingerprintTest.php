<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TreeFingerprintTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed}>
     */
    public static function values(): array
    {
        $deep = 'x';
        for ($i = 0; $i < 200; $i++) {
            $deep = '{^' . $deep . '^}';
        }
        $long = str_repeat('{*a*} b /c/ ', 12_000);

        return [
            'scalars' => [['a' => [1, -2, 2.5, 0.1, null, true, false, '', "q\"\n"], 7 => 'x', 'z' => []]],
            'paragraph' => [self::tree("a *b* /c/ `d` [e](/f) {.g}\n\n- h\n")],
            'deep nest' => [self::tree($deep . "\n")],
            'past one chunk' => [self::tree($long . "\n")],
        ];
    }

    #[DataProvider('values')]
    public function testFingerprintHashesTheSerializedFields(mixed $value): void
    {
        $context = hash_init('xxh128');
        self::reference($context, $value);

        self::assertSame(hash_final($context), TreeEquality::fingerprint($value));
    }

    /**
     * @return array<mixed>
     */
    private static function tree(string $source): array
    {
        return TreeEncoder::decodedForWriter(Carve::parse($source));
    }

    /**
     * The stream `fingerprint()` hashes, one `serialize()` per scalar and key, as `normalize()` orders it.
     */
    private static function reference(\HashContext $context, mixed $value): void
    {
        $value = TreeEquality::normalize($value);
        self::feed($context, $value);
    }

    private static function feed(\HashContext $context, mixed $value): void
    {
        if (!is_array($value)) {
            hash_update($context, serialize($value));

            return;
        }
        if (array_is_list($value)) {
            hash_update($context, 'l' . count($value) . ':');
            foreach ($value as $item) {
                self::feed($context, $item);
            }

            return;
        }
        hash_update($context, 'm' . count($value) . ':');
        foreach ($value as $name => $item) {
            hash_update($context, serialize($name));
            self::feed($context, $item);
        }
    }
}
