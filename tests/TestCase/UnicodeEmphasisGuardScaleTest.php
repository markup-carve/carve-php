<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Unicode word and grapheme-extender guards of bare emphasis read one
 * character: a `/u` match against the whole paragraph validates all of it per call.
 */
class UnicodeEmphasisGuardScaleTest extends TestCase
{
    use ScalingGuardTrait;

    #[Group('scaling')]
    public function testNonAsciiFlanksScaleLinearly(): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->convert($input);
            },
            str_repeat('Москва/Питер/Киев _ж_ *д*ж ', 1000),
            str_repeat('Москва/Питер/Киев _ж_ *д*ж ', 8000),
            'emphasis guards beside non-ASCII words',
            1000,
            8000,
            maxPerByteRatio: 2.0,
        );
    }

    public function testNonAsciiWordsStillGuardEmphasis(): void
    {
        self::assertSame(
            '<p>Москва/Питер/Киев <u>ж</u> *д*ж</p>',
            (new CarveConverter())->convert('Москва/Питер/Киев _ж_ *д*ж'),
        );
    }
}
