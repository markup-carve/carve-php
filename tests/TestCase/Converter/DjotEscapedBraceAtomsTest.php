<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotEscapedBraceAtomsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literalDelimiterProvider(): iterable
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-escaped-brace-atoms.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            yield $row['name'] => [$row['source'], $row['carveHtml'] ?? $row['html']];
        }
    }

    #[DataProvider('literalDelimiterProvider')]
    public function testLiteralDelimiterBoundaries(string $source, string $expected): void
    {
        $html = trim((new CarveConverter())->convert((new DjotToCarve())->convert($source)));
        $html = str_replace('&nbsp;', "\u{00a0}", $html);
        $html = preg_replace('/<\/?tbody>/', '', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        $expected = str_replace('&nbsp;', "\u{00a0}", $expected);
        $expected = preg_replace('/<img alt="([^"]*)" src="([^"]*)">/', '<img src="$2" alt="$1">', $expected);
        self::assertSame($expected, $html);
    }

    public function testUserPlaceholdersAndFrontmatterArePreserved(): void
    {
        $token = "\0DJOTINVALIDATTR0\0";
        foreach (['', "---\nlabel: " . $token . "\n---\n\n"] as $prefix) {
            $converted = (new DjotToCarve())->convert($prefix . $token . ' w{x}{.c}');
            self::assertStringContainsString($token, $converted);
            self::assertStringNotContainsString("\0DJOTINVALIDATTR1\0", $converted);
            if ($prefix !== '') {
                self::assertStringStartsWith($prefix, $converted);
            }
        }
    }

    public function testRawBraceFootnoteLabelKeepsItsDefinition(): void
    {
        $converted = (new DjotToCarve())->convert("[^a{b}]: note\n\nsee [^a{b}]");
        $html = (new CarveConverter())->convert($converted);
        self::assertStringContainsString('<li id="fn1"><p>note', preg_replace('/>\s+</', '><', $html));
        self::assertStringContainsString('href="#fn1"', $html);
        self::assertStringNotContainsString('href="#fn2"', $html);
        self::assertStringNotContainsString('DJOTINVALIDATTR', $converted);
    }
}
