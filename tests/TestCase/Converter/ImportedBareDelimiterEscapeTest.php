<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\EscapesCarveConstructs;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImportedBareDelimiterEscapeTest extends TestCase
{
    #[DataProvider('delimiterProvider')]
    public function testPlainDelimiterPairing(string $source, string $expected): void
    {
        $escaper = new class {
            use EscapesCarveConstructs;

            public function escape(string $source): string
            {
                return $this->escapePlainCarveInlineSyntax($source);
            }
        };
        self::assertSame($expected, $escaper->escape($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function delimiterProvider(): iterable
    {
        foreach (str_split('/=~*_') as $delimiter) {
            yield $delimiter . ' adjacent' => ['x ' . $delimiter . 'a' . $delimiter . $delimiter . ' y', 'x \\' . $delimiter . 'a' . $delimiter . $delimiter . ' y'];
            yield $delimiter . ' internal' => ['x ' . $delimiter . 'a' . $delimiter . 'b' . $delimiter . ' y', 'x \\' . $delimiter . 'a' . $delimiter . 'b' . $delimiter . ' y'];
            yield $delimiter . ' escaped closer' => [$delimiter . 'a\\' . $delimiter, $delimiter . 'a\\' . $delimiter];
            yield $delimiter . ' escaped brace' => ['\\{' . $delimiter . 'a' . $delimiter . '}', '\\{\\' . $delimiter . 'a' . $delimiter . '}'];
        }
    }

    #[DataProvider('sourceProvider')]
    public function testImportKeepsEqualsLiteral(string $source, string $expected): void
    {
        foreach ([new MarkdownToCarve(), new DjotToCarve()] as $importer) {
            $carve = $importer->convert($source . "\n");
            self::assertSame($expected . "\n", $carve);
            self::assertSame(false, str_contains((new CarveConverter())->convert($carve), '<mark>'));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'adjacent closer' => ['=a==', '\=a=='],
            'internal delimiter' => ['=a=b=', '\=a=b='],
            'surrounding words' => ['x =a== y', 'x \=a== y'],
            'parentheses' => ['(=a=b=)', '(\=a=b=)'],
            'early closer' => ['=a= b=', '\=a= b='],
            'multiple openers' => ['=a =b= c=', '\=a \=b= c='],
            'word boundaries' => ['a=b=c', 'a=b=c'],
            'spaced delimiters' => ['x = 5 = y', 'x = 5 = y'],
            'doubled opener' => ['==a==', '==a=='],
            'assignment' => ['key=value=', 'key=value='],
            'space before closer' => ['=a =', '=a ='],
            'space after opener' => ['= a=', '= a='],
            'line break' => [
                '=a
b==', '\=a
b==',
            ],
            'blank line' => [
                '=a

b=', '=a

b=',
            ],
            'no-break space' => ['= a=', '\= a='],
            'escaped opener' => ['\=a=b=', '\=a=b='],
        ];
    }
}
