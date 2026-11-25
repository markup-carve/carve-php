<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TreeEqualityTest extends TestCase
{
    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function pairs(): array
    {
        $tree = self::tree("a *b* _c_ `d` [e](/f){.g}\n\n- h\n\n> i\n");
        $table = self::tree("|= a |= b |\n| c | d |\n");
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $paragraph = static fn (array $children): array => ['type' => 'paragraph', 'children' => $children];

        return [
            'same tree' => [$tree, $tree],
            'fields in another order' => [$tree, self::reversed($tree)],
            'positions on one side' => [
                $paragraph([$text('a')]),
                $paragraph([$text('a') + ['pos' => ['startOffset' => 0, 'endOffset' => 1]]]),
            ],
            'adjacent texts against one' => [$paragraph([$text('a'), $text('b')]), $paragraph([$text('ab')])],
            'adjacent texts against another' => [$paragraph([$text('a'), $text('b')]), $paragraph([$text('ac')])],
            'other value' => [$paragraph([$text('a')]), $paragraph([$text('b')])],
            'missing field' => [['type' => 'image', 'src' => 'i', 'alt' => ''], ['type' => 'image', 'src' => 'i']],
            'extra field' => [['type' => 'image', 'src' => 'i'], ['type' => 'image', 'src' => 'i', 'alt' => '']],
            'other list length' => [$paragraph([$text('a')]), $paragraph([$text('a'), ['type' => 'soft_break']])],
            'scalar types' => [['a' => 1], ['a' => '1']],
            'empty map and list' => [[], ['type' => 'x', 'pos' => 1]],
            'table' => [$table, self::reversed($table)],
            'map against list' => [['x' => 1], [1]],
            // Only a node's `pos` is a position; a `pos` in an untyped map such as extension props is data.
            'pos in props' => [
                ['type' => 'inline_extension', 'props' => ['pos' => 'a']],
                ['type' => 'inline_extension', 'props' => ['pos' => 'b']],
            ],
            'pos in an untyped map' => [['pos' => 1, 'x' => 'y'], ['pos' => 2, 'x' => 'y']],
        ];
    }

    #[DataProvider('pairs')]
    public function testSameAgreesWithEqual(mixed $a, mixed $b): void
    {
        $equal = TreeEquality::equal($a, $b);

        self::assertSame($equal, TreeEquality::same($a, $b));
        self::assertSame($equal, TreeEquality::same($b, $a));
    }

    /**
     * @return array<mixed>
     */
    private static function tree(string $source): array
    {
        return TreeEncoder::decodedForWriter(Carve::parse($source));
    }

    private static function reversed(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::reversed(...), $value);

        return array_is_list($value) ? $value : array_reverse($value, true);
    }
}
