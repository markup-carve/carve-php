<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment between two sibling lists in a tight item parts nothing (carve#4716), so the
 * writer still spells their N1a boundary behind it (carve#4759, carve#4760; corpus 914-4, 916).
 */
class ACommentKeepsTheBoundaryBetweenSiblingListsInAnItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'nested lists' => [
                "- o\n  - a\n\n\n\n  %% c\n\n  - b\n",
                "- o\n  - a\n\n  %% c\n\n\n\n  - b\n",
            ],
            'nested lists, short run' => [
                "- o\n  - a\n\n\n  %% c\n\n  - b\n",
                "- o\n  - a\n\n  %% c\n\n\n\n  - b\n",
            ],
            'description lists' => [
                "- o\n\n  :: t\n  : x\n\n  %% c\n\n\n  :: u\n  : v\n",
                "- o\n  :: t\n  : x\n\n  %% c\n\n\n\n  :: u\n  : v\n",
            ],
            'description lists in a description body' => [
                ":: t0\n: d\n  :: t\n  : x\n\n\n\n  %% c\n  :: u\n  : v\n",
                ":: t0\n: d\n\n  :: t\n  : x\n\n  %% c\n\n\n\n  :: u\n  : v\n",
            ],
            'comment closing an item after a description list' => [
                "- o\n  :: t\n  : x\n\n  %% c\n",
                "- o\n  :: t\n  : x\n\n  %% c\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testTheBoundarySurvivesFmt(string $source, string $expected): void
    {
        $converter = CarveConverter::create();
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($expected, $formatted);
        $this->assertSame($converter->convert($source), $converter->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
