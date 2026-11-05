<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ADefinitionShapedFinalCodeLineIsPaddedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'following sibling in footnote' => ["r[^n]\n\n[^n]: p `a\n  [x]: y` q\n", "r[^n]\n\n[^n]: p ` a\n   [x]: y ` q\n"],
            'last sibling after strike marker' => ["~``` x\n[d]: u ```\n", "~` x\n [d]: u `\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testFinalLineIsPaddedAndTheWriterIsStable(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::toCarve($source));
        $this->assertSame($expected, CarveConverter::toCarve($expected));
        $converter = CarveConverter::create();
        $this->assertSame($converter->convert($source), $converter->convert($expected));
    }
}
