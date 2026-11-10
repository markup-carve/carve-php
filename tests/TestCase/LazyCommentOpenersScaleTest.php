<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A series of lazy comment openers shares one later closer (carve#5870).
 */
final class LazyCommentOpenersScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'item' => ["- x\n", "{% a\n", " z %}\n"],
            'quote' => ["> - x\n", "> {% a\n", ">  z %}\n"],
            'nested quote in item' => ["- x\n  > y\n", "  > {% a\n", "  >  z %}\n"],
        ];
    }

    #[Group('scaling')]
    #[DataProvider('shapes')]
    public function testSharedCloserScalesLinearly(string $head, string $opener, string $closer): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->convert($input);
            },
            $head . str_repeat($opener, 200) . $closer,
            $head . str_repeat($opener, 800) . $closer,
            json_encode([$head, $opener, $closer], JSON_THROW_ON_ERROR),
            200,
            800,
            maxPerByteRatio: 1.5,
        );
    }
}
