<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedTightListWriterTest extends TestCase
{
    use RuleATodo;

    public static function writerCases(): iterable
    {
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/fixtures/nested_tight_list_writer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $index => $row) {
            yield (string)$index => [$row['source'], $row['html'], $row['fmt'], $index < 6];
        }
    }

    #[DataProvider('writerCases')]
    public function testNestedWriterMatchesReference(string $source, string $expectedHtml, string $expectedFmt, bool $noIndentedPlus): void
    {
        $written = CarveConverter::toCarve($source);
        self::assertSame($expectedFmt, $written, $source);
        if ($noIndentedPlus) {
            self::assertDoesNotMatchRegularExpression('/^[ \t]+\+[ \t]*$/m', $written);
        }
        $converter = new CarveConverter();
        self::assertSame(self::normalize($expectedHtml), self::normalize($converter->convert($written)));
        self::assertSame($written, CarveConverter::toCarve($written));
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
