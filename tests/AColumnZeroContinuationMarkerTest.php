<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AColumnZeroContinuationMarkerTest extends TestCase
{
    public static function markerCases(): iterable
    {
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/fixtures/column_zero_continuation_marker.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $index => $row) {
            yield (string)$index => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('markerCases')]
    public function testColumnZeroMarkerReading(string $source, string $expected): void
    {
        self::assertSame(self::normalize($expected), self::normalize((new CarveConverter())->convert($source)), $source);
    }

    public function testHostedDivStillOwnsAQuotedShiftedLineBlock(): void
    {
        $source = "> - lead\n>   ::: box\n>     ::: |\n>     x\n>     :::\n>   :::\n";
        $expected = '<blockquote><ul><li>lead<div class="box"><div class="line-block"><p>x</p></div></div></li></ul></blockquote>';
        $actual = preg_replace('/\s+</', '<', (new CarveConverter())->convert($source));
        self::assertSame($expected, trim((string)$actual));
    }

    private static function normalize(string $html): string
    {
        $insidePre = false;
        $out = [];
        foreach (explode("\n", trim($html)) as $line) {
            if ($insidePre) {
                $out[] = $line;
            } elseif (trim($line) !== '') {
                $out[] = trim($line);
            }
            if (str_contains($line, '<pre')) {
                $insidePre = true;
            }
            if (str_contains($line, '</pre>')) {
                $insidePre = false;
            }
        }

        return implode("\n", $out);
    }
}
