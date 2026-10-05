<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scaling')]
class BracedOpaqueCloserScalingTest extends TestCase
{
    use ScalingGuardTrait;

    public function testRepeatedSubstitutionOpenersScaleLinearly(): void
    {
        $converter = CarveConverter::create();
        $this->assertConversionScalesLinearly(
            static fn (string $source): string => $converter->convert($source),
            '~a ' . str_repeat('{~b ', 1024) . '~}',
            '~a ' . str_repeat('{~b ', 4096) . '~}',
            'substitution openers without an arrow',
            1024,
            4096,
        );
    }

    public function testAttributeOpenersBeforeAnOpaqueCloserScaleLinearly(): void
    {
        $converter = CarveConverter::create();
        foreach (['`_}`', '[_}]', '{*`_}`*}'] as $suffix) {
            $small = 'a ' . str_repeat('{_k=x} ', 1024) . $suffix;
            $large = 'a ' . str_repeat('{_k=x} ', 4096) . $suffix;
            $this->assertConversionScalesLinearly(
                static fn (string $source): string => $converter->convert($source),
                $small,
                $large,
                'attribute openers before an opaque closer',
                1024,
                4096,
            );
        }
    }
}
