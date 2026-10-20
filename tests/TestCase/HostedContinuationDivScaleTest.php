<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scaling')]
final class HostedContinuationDivScaleTest extends TestCase
{
    use ScalingGuardTrait;

    public function testLazyQuoteLinesInsideAHostedDivScaleLinearly(): void
    {
        $converter = new CarveConverter();
        $prefix = "> - lead\n>   ::: box\n>     ::: |\n>     x\n";
        $suffix = ">     :::\n>   :::\n";

        $this->assertConversionScalesLinearly(
            static function (string $source) use ($converter): void {
                $converter->convert($source);
            },
            $prefix . str_repeat("lazy text\n", 2000) . $suffix,
            $prefix . str_repeat("lazy text\n", 8000) . $suffix,
            'lazy quote lines in a continuation-line hosted div',
            2000,
            8000,
            maxPerByteRatio: 2.5,
        );
    }
}
