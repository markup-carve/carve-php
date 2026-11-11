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
            'a list after a description\'s refused plus (corpus 1017)' => [
                ":: t\n: +\n1. li\n+\n  - sib\n", ['list 9..14', 'list_item 9..14', 'list 19..24', 'list_item 19..24'],
            ],
            'a list indented past a description\'s content column' => [
                ":: t\n: +\n1. li\n+\n    - sib\n", ['list 9..14', 'list_item 9..14', 'list 19..26', 'list_item 19..26'],
            ],
            'a tab across a description\'s content column' => [
                ":: t\n: +\n\t- sib\n", ['list 9..15', 'list_item 9..15'],
            ],
            'a quoted tab that ends at a description\'s content column' => [
                "> :: t\n> : +\n> \t- sib\n", ['list 16..21', 'list_item 16..21'],
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
