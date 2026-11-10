<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A failed opener claims no later line, so each following opener is decided once.
 */
class RepeatedBlockCommentOpenersScaleTest extends TestCase
{
    use ScalingGuardTrait;

    #[Group('scaling')]
    #[DataProvider('shapes')]
    public function testRepeatedOpenersScaleLinearly(string $prefix, string $opener, string $closer, string $suffix): void
    {
        $small = $prefix . str_repeat($opener, 2000) . $closer . $suffix;
        $large = $prefix . str_repeat($opener, 8000) . $closer . $suffix;
        $this->assertConversionScalesLinearly(
            static function (string $input): void {
                (new CarveConverter())->convert($input);
            },
            $small,
            $large,
            json_encode($prefix, JSON_THROW_ON_ERROR),
            2000,
            8000,
        );
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'item' => ["- x\n\n", "  {% a\n", "   z %}\n", "- s\n"],
            'nested item' => ["- x\n  - y\n\n", "    {% a\n", "     z %}\n", "- s\n"],
            'quote' => ["> x\n>\n", "> {% a\n", ">  z %}\n", ''],
        ];
    }
}
