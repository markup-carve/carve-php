<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition is a leaf, so a container prefix and top-level indentation are outside its span (carve#4952).
 */
class ALinkReferenceDefinitionStartsAtItsBracketTest extends TestCase
{
    /**
     * @return array<array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            ["> - para\n>   [A]: b\n", '[A]: b'],
            [">  [A]: b\n", '[A]: b'],
            ["  [A]: b\n", '[A]: b'],
            ["> [f]: ~\n/\n", '[f]: ~'],
            ["> > [A]: b\n", '[A]: b'],
            ["- [A]: b\n", '[A]: b'],
            ["1. [A]: b\n", '[A]: b'],
            [":: t\n: [A]: b\n", '[A]: b'],
            ["[^n]: [A]: b\n", '[A]: b'],
            ["> [^n]: [A]: b\n", '[A]: b'],
            [">   - x\n>\n>     [A]: b\n", '[A]: b'],
            ["\u{1F600}\n> [A]: b\n", '[A]: b'],
            ["[A]: b\n", '[A]: b'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheSpanStartsAtTheBracket(string $source, string $span): void
    {
        $pos = self::definitionPos($source);
        self::assertSame($span, mb_substr($source, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']));
    }

    public function testTheColumnIsTheBracketsColumn(): void
    {
        $pos = self::definitionPos("  [A]: b\n");
        self::assertSame([3, 9], [$pos['startColumn'], $pos['endColumn']]);
    }

    /**
     * @return array<string, int>
     */
    private static function definitionPos(string $source): array
    {
        $tree = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
        foreach ($tree['children'] as $child) {
            if ($child['type'] === 'link_reference_definition') {
                return $child['pos'];
            }
        }
        self::fail('no link_reference_definition');
    }
}
