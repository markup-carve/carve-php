<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No empty payload line: it would be a blank that loosens a list under §17 L1c
 * (carve#5022 ruling A).
 */
class AnEmptyCommentSpanIsWrittenAsAdjacentDelimitersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'a description lead in a list item' => ["- a\n  :: u\n  : %%%\n  %%%\n- c\n", "- a\n  :: u\n  : %%%\n    %%%\n- c\n"],
            'a list marker line' => ["- %%%\n  %%%\n- c\n", "- %%%\n  %%%\n- c\n"],
            'an item before its sibling' => ["- a\n  %%%\n  %%%\n- c\n", "- a\n  %%%\n  %%%\n- c\n"],
            'a note lead' => ["x[^n]\n\n[^n]: %%%\n  %%%\n", "x[^n]\n\n[^n]: %%%\n  %%%\n"],
            'the document' => ["%%%\n%%%\n", "%%%\n%%%\n"],
            'an unclosed span' => ["%%%\n", "%%%\n%%%\n"],
        ];
    }

    #[DataProvider('provider')]
    public function testTheSpanIsWrittenWithoutAPayloadLine(string $source, string $expected): void
    {
        $written = CarveConverter::toCarve($source);

        $this->assertSame($expected, $written);
        $this->assertSame($written, CarveConverter::toCarve($written));
        $this->assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($written));
    }

    public function testAPayloadOfOneEmptyLineIsKept(): void
    {
        $this->assertSame("%%%\n\n\n%%%\n", CarveConverter::toCarve("%%%\n\n\n%%%\n"));
    }
}
