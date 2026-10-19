<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scaling')]
class TableDisplayWidthScaleTest extends TestCase
{
    use ScalingGuardTrait;

    public function testAlignmentScalesLinearly(): void
    {
        foreach (['🇦', 'a'] as $character) {
            $make = static fn (int $count): string => '| ' . str_repeat($character, $count) . " | x |\n| y | z |\n";
            $this->assertConversionScalesLinearly(
                static function (string $source): void {
                    CarveConverter::toCarve($source, true);
                },
                $make(1000),
                $make(2000),
                'table alignment',
                1000,
                2000,
            );
        }
    }
}
