<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5207: a document-owned comment keeps its source slot between list
 * items, and adjacent comment lines across owners form one block.
 */
class ADocumentOwnedCommentRunKeepsItsListGapTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'document-owned lines between three items' => [
                "- a\n%% c1\n\n- b\n%% c2\n\n- c\n%% c3\n",
                "- a\n\n%% c1\n\n- b\n\n%% c2\n\n- c\n\n%% c3\n",
            ],
            'mixed-owner run from corpus row 945' => [
                "- a\n\n  %% y\n%% z\n- c\n",
                "- a\n\n  %% y\n%% z\n\n- c\n",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testFmtKeepsTheRunInPlaceAndReadsItBack(string $source, string $expected): void
    {
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($expected, $formatted);
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
    }
}
