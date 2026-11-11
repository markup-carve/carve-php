<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\SourceLinter;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 CARVE-P12-013 as ruled on carve#5976: an indented description starts
 * at its owner's content column, the carve#5063 reading for lists.
 */
class ADescriptionStartsAtItsOwnersContentColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function documents(): array
    {
        return [
            'an indented sibling at top level (corpus 799-2)' => [":: t\n: a\n : b\n", ['5..8', '9..13']],
            'a tab-indented sibling at top level' => [":: t\n\t: b\n", ['5..9']],
            'past an item\'s content column' => ["- :: t\n   : b\n", ['9..13']],
            'past a quote marker' => ["> :: t\n>  : b\n", ['9..13']],
            'past an ordered item\'s content column' => ["1. a\n   :: t\n    : b\n", ['16..20']],
            'a plus host at the item\'s content column (corpus 806)' => ["1. a\n   :: t\n   : +\n> q\n", ['16..19']],
            'in an admonition' => ["::: note\n:: t\n: a\n : b\n:::\n", ['14..17', '18..22']],
            'in an indented description body' => [":: t\n : a\n   :: u\n   : b\n", ['5..24', '21..24']],
            'in a description body' => [":: t\n:  a\n\n   :: u\n     : b\n", ['5..27', '22..27']],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('documents')]
    public function testTheDescriptionStartsAtTheOwnersContentColumn(string $source, array $expected): void
    {
        $this->assertSame($expected, self::descriptions(CarveConverter::carve()->parse($source)));
    }

    public function testABlockAtTheDescriptionsColumnStaysUnflagged(): void
    {
        $warnings = (new SourceLinter())->lint("- :: t\n   : b\n\n     > q\n");

        $this->assertSame([], array_map(static fn ($warning): string => $warning->rule, $warnings));
    }

    /**
     * @return list<string>
     */
    private static function descriptions(Node $node): array
    {
        $spans = [];
        if ($node->getType() === 'definition_description') {
            $pos = $node->getPos();
            $spans[] = $pos === null ? '-' : $pos->startOffset . '..' . $pos->endOffset;
        }
        foreach ($node->getChildren() as $child) {
            array_push($spans, ...self::descriptions($child));
        }

        return $spans;
    }
}
