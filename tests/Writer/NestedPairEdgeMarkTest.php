<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#6188, C2-W-069: a mark at the edge of a nested pair's text stands next to that pair's delimiter, so it can
 * pair with the outer delimiter and the outer pair is braced, as carve-js writes it.
 */
final class NestedPairEdgeMarkTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function trees(): array
    {
        return [
            'strong holding an emphasis of a star' => ['strong', 'emphasis', '*', "{*/*/*}\n"],
            'emphasis holding a strong of a slash' => ['emphasis', 'strong', '/', "{/*/*/}\n"],
            'strong holding an emphasis of a word' => ['strong', 'emphasis', 'x', "*/x/*\n"],
            'strong holding an emphasis ending in a star' => ['strong', 'emphasis', 'a*', "{*/a*/*}\n"],
        ];
    }

    public function testAStarInTextBesideTheNestedPairIsEscapedInstead(): void
    {
        // The C2-W-088 round for `*` escapes the star of the emphasis as well, so the strong needs no brace.
        $children = [
            [

                'type' => 'strong',
                'children' => [
                    ['type' => 'text', 'value' => 'b *'],
                    ['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => '*']]],
                ],
            ],
        ];
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => $children]]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame([], $result->codes());
        $this->assertSame("*b \\*/\\*/*\n", $result->source);
    }

    #[DataProvider('trees')]
    public function testTheTreeIsWrittenAndReadsBack(string $outer, string $inner, string $value, string $expected): void
    {
        $children = [['type' => $outer, 'children' => [['type' => $inner, 'children' => [['type' => 'text', 'value' => $value]]]]]];
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => $children]]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame([], $result->codes());
        $this->assertSame($expected, $result->source);
        $back = json_decode(Carve::toJson($result->source), true);
        $this->assertIsArray($back);
        $this->assertEquals($tree['children'], self::strip($back)['children']);
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function strip(array $node): array
    {
        unset($node['pos'], $node['srcByteLength']);
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::strip($value);
            }
        }

        return $node;
    }
}
