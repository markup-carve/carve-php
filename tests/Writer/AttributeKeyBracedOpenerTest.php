<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-125, case nine (carve#6570): where the braced opener of a node reads as the key of a valid attribute block,
 * the `=` behind the key characters of its content takes the backslash. Outputs match carve-js.
 */
final class AttributeKeyBracedOpenerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'CC-2021 underline of a dot' => [
            '{_=\\._}',
            '{_\\=._}',
        ];

        yield 'underline of a bang' => [
            '{_=\\!_}',
            '{_\\=!_}',
        ];

        yield 'underline of a bang behind text' => [
            'x {_=\\!_}',
            'x {_\\=!_}',
        ];

        yield 'key characters before the equals' => [
            'a {_ab-c=\\._} b',
            'a {_ab-c\\=._} b',
        ];

        yield 'two underlines in one paragraph' => [
            '{_=\\._} {_b=\\._}',
            '{_\\=._} {_b\\=._}',
        ];

        yield 'an escaped closing mark already breaks the block' => [
            'a {_ab-c=\\_._} b',
            'a {_ab-c=\\_._} b',
        ];
    }

    #[DataProvider('cases')]
    public function testKeyEqualsTakesTheBackslash(string $input, string $expected): void
    {
        $out = Carve::format($input . "\n");
        self::assertSame($expected . "\n", $out);
        self::assertSame(
            TreeEquality::normalize(TreeEncoder::decodedForWriter(Carve::parse($input . "\n"))),
            TreeEquality::normalize(TreeEncoder::decodedForWriter(Carve::parse($out))),
        );
    }
}
