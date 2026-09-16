<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §27 reinterprets a `!` only where it abuts a following backtick run,
 * so everywhere else the backslash adds an `escaped_text` node the source never
 * held - which is what PART 11 §2 forbids. The guard used to decide the minimal
 * pass alone, so a unit the writer had escalated got `\!` where carve-js and
 * carve-rs write the character bare (markup-carve/carve-php#2013).
 *
 * Every shape here nests a same-marker span inside another, which is what
 * escalates the unit. The binding case, where the backslash IS the only
 * spelling, lives in VerbatimSigilEscapeIsStructuralTest.
 */
class ABangIsBareInTheConservativePassTooTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function escalatedProvider(): array
    {
        return [
            'the reported shape' => ['a {*a {*!x*} b*} b', "a *a {\\*!x* b*} b\n"],
            'a trailing bang' => ['a {*a {*x!*} b*} b', "a *a {*x!* b*\\} b\n"],
            'a bang between letters' => ['a {*a {*p!q*} b*} b', "a *a {*p!q* b*\\} b\n"],
            'a bang alone' => ['a {*a {*!*} b*} b', "a *a {\\*!* b*} b\n"],
            'two bangs' => ['a {*a {*!!x*} b*} b', "a *a {\\*!!x* b*} b\n"],
            'an italic unit' => ['a {/a {/!x/} b/} b', "a /a {\\/!x/ b/} b\n"],
            'an underline unit' => ['a {_a {_!x_} b_} b', "a _a {\\_!x_ b_} b\n"],
            'a strike unit' => ['a {~a {~!x~} b~} b', "a ~a {\\~!x~ b~} b\n"],
            'a highlight unit' => ['a {=a {=!x=} b=} b', "a =a {\\=!x= b=} b\n"],
        ];
    }

    #[DataProvider('escalatedProvider')]
    public function testTheBangIsWrittenBare(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::toCarve($source));
    }

    #[DataProvider('escalatedProvider')]
    public function testTheWrittenFormReadsBackAsTheSourceDoes(string $source, string $expected): void
    {
        $converter = new CarveConverter();

        $this->assertSame($converter->convert($source), $converter->convert($expected));
    }
}
