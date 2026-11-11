<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 CARVE-P12-013 and the carve#5063 ruling: a list or item starts at its
 * owner's content column, on the line as the author wrote it (carve-php#5162).
 */
class AListStartsAtItsOwnersContentColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function documents(): array
    {
        return [
            'a quoted sublist after an attribute run (corpus 821-3)' => [
                "> - a\n> +\n> {x}\n>   - c\n", ['list 2..5', 'list_item 2..5', 'list 18..23', 'list_item 18..23'],
            ],
            'a list in an indented quote (corpus 1056)' => [
                "  > - {% a\n>    b %}\n", ['list 4..20', 'list_item 4..20'],
            ],
            'a later item in an indented quote' => [
                " > - a\n > - c\n", ['list 3..13', 'list_item 3..6', 'list_item 10..13'],
            ],
            'a tab-indented sibling item (corpus 245)' => [
                "- a\n    - b\n \t- c\n", ['list 0..17', 'list_item 0..17', 'list 6..17', 'list_item 6..11', 'list_item 13..17'],
            ],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('documents')]
    public function testTheListSpansStartAtTheOwnersContentColumn(string $source, array $expected): void
    {
        $this->assertSame($expected, self::lists(CarveConverter::carve()->parse($source)));
    }

    /**
     * @return list<string>
     */
    private static function lists(Node $node): array
    {
        $lines = [];
        if (in_array($node->getType(), ['list', 'list_item'], true)) {
            $pos = $node->getPos();
            $lines[] = $node->getType() . ' ' . ($pos === null ? '-' : $pos->startOffset . '..' . $pos->endOffset);
        }
        foreach ($node->getChildren() as $child) {
            array_push($lines, ...self::lists($child));
        }

        return $lines;
    }
}
