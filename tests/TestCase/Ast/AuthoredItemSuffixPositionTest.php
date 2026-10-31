<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuthoredItemSuffixPositionTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function cases(): array
    {
        return [
            'attribute before nested list' => ["- a\n\n   {.c}\n   - b\n", 18],
            'indented nested list' => ["- a\n\n   - b\n", 10],
            'indented quote' => ["- a\n\n   > b\n", 10],
        ];
    }

    #[DataProvider('cases')]
    public function testAuthoredSuffixSetsLeafAndContainerEnd(string $source, int $lastOffset): void
    {
        $tree = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
        $found = [];
        $visit = function (array $node) use (&$visit, &$found): void {
            if (($node['type'] ?? null) === 'text' && ($node['value'] ?? null) === 'b') {
                $found[] = $node;
            }
            foreach (['children', 'items'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    $visit($child);
                }
            }
        };
        $visit($tree);

        $this->assertCount(1, $found);
        $this->assertSame($lastOffset, $found[0]['pos']['startOffset']);
        $this->assertSame($lastOffset + 1, $found[0]['pos']['endOffset']);
        $this->assertSame($lastOffset + 1, $tree['children'][0]['pos']['endOffset']);
    }
}
