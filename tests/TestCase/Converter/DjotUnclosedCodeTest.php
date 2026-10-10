<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotUnclosedCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function codeProvider(): iterable
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-unclosed-code.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            yield $row['name'] => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('codeProvider')]
    public function testUnclosedCode(string $source, string $expected): void
    {
        $html = trim((new CarveConverter())->convert((new DjotToCarve())->convert($source)));
        $html = preg_replace('/<\/?tbody>/', '', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        $html = str_replace(["<li>\n", "\n</li>"], ['<li>', '</li>'], $html);
        self::assertSame($expected, $html);
    }
}
