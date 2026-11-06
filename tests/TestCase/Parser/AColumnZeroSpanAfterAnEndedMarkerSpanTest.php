<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` span opened behind a list marker is owned by that marker's item, so a
 * line below the item's content column ends it (PART 0 S1, CARVE-P0-005) and a
 * later column-0 `%%%` opens a new span at the document (markup-carve/carve#5297).
 */
final class AColumnZeroSpanAfterAnEndedMarkerSpanTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function cases(): array
    {
        return [
            'carve#5297: nested ordered item' => [
                ". a\n  . %%%\n    n\n  zz\n%%%\n",
                ['list', 'comment'],
            ],
            'bullet items' => [
                "- a\n  - %%%\n    n\n  zz\n%%%\n",
                ['list', 'comment'],
            ],
            'wider inner marker' => [
                "- a\n  1. %%%\n     n\n  zz\n%%%\n",
                ['list', 'comment'],
            ],
            'closer still inside the item column stays in the item' => [
                ". a\n  . %%%\n    n\n    %%%\n",
                ['list'],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $expected
     */
    #[DataProvider('cases')]
    public function testDocumentChildren(string $source, array $expected): void
    {
        /** @var array<array{type: string}> $children */
        $children = (new AstCodec())->encode((new CarveConverter())->parse($source))['children'];

        self::assertSame($expected, array_column($children, 'type'));
    }

    public function testFormatterKeepsTheCommentAtTheDocument(): void
    {
        self::assertSame(
            ". a\n  . %%%\n    n\n    %%%\n  zz\n\n%%%\n%%%\n",
            CarveConverter::toCarve(". a\n  . %%%\n    n\n  zz\n%%%\n"),
        );
    }
}
