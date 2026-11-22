<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-005, C2-B-036, C2-B-043: a decimal list first in the body of a bare dot item is written with numbers.
 */
final class NestedBareDotListTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function cases(): array
    {
        $list = static fn (array ...$items): array => [
            'type' => 'list',
            'marker' => ['kind' => 'ordered', 'style' => '1', 'delimiter' => '.'],
            'spacing' => 'tight',
            'children' => array_map(static fn (array $kids): array => ['type' => 'list_item', 'children' => $kids], $items),
        ];
        $paragraph = static fn (string $text): array => ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $text]]];
        $quote = static fn (array $kids): array => ['type' => 'block_quote', 'children' => $kids];

        return [
            'two levels' => [$list([$list([$paragraph('a')])]), ". 1. a\n"],
            'three levels' => [$list([$list([$list([$paragraph('a')])])]), ". 1. . a\n"],
            'in a quote' => [$quote([$list([$list([$paragraph('a')])])]), "> . 1. a\n"],
            'a quote between' => [$list([$quote([$list([$paragraph('a')])])]), ". > . a\n"],
            'second inner item' => [$list([$list([$paragraph('a')], [$paragraph('b')])]), ". 1. a\n  2. b\n"],
            'after a paragraph' => [$list([$paragraph('a'), $list([$paragraph('b')])]), ". a\n  . b\n"],
        ];
    }

    /**
     * @param array<mixed> $block
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testTheInnerListReadsBack(array $block, string $source): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [$block]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->diagnostics);
        $read = TreeEncoder::decoded(Carve::parse($source));
        $this->assertSame(self::shape([$block]), self::shape(is_array($read['children'] ?? null) ? $read['children'] : []));
    }

    /**
     * @return void
     */
    public function testATaskBoxKeepsTheInnerBareDot(): void
    {
        $this->assertSame(". [ ] . a\n", Writer::format(". [ ] . a\n"));
    }

    /**
     * The block types and the text, without positions or facts.
     *
     * @param array<mixed> $nodes
     *
     * @return string
     */
    private static function shape(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $node = is_array($node) ? $node : [];
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            if ($type === 'text') {
                $out .= is_string($node['value'] ?? null) ? $node['value'] : '';

                continue;
            }
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $out .= '(' . $type . ' ' . self::shape($children) . ')';
        }

        return $out;
    }
}
