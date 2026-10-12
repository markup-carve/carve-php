<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownParagraphListPreflightTest extends TestCase
{
    #[DataProvider('listHosts')]
    public function testLooseItemsKeepSeparatorsBetweenTheirBlocks(string $first, string $second, string $prefix, string $intro): void
    {
        $indent = str_repeat(' ', strlen($first) + 1);
        $blank = rtrim($prefix);
        $source = $intro . $prefix . $first . " first\n" . $prefix . $indent . "# heading\n" . $blank . "\n" . $prefix . $second . " second\n";
        $expected = $intro . $prefix . $first . " first\n" . $blank . "\n" . $prefix . $indent . "# heading\n" . $blank . "\n" . $prefix . $second . " second\n";
        $converter = new class extends MarkdownToCarve {
            public function separate(string $source): string
            {
                return $this->separateLooseItems($source, []);
            }
        };

        self::assertSame($expected, $converter->separate($source));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function listHosts(): array
    {
        $rows = [];
        foreach ([['-', '-'], ['*', '*'], ['1.', '2.'], ['1)', '2)'], ['a.', 'b.'], ['A)', 'B)'], ['iv.', 'v.'], ['IX)', 'X)']] as [$first, $second]) {
            foreach (['', '> ', '> > '] as $prefix) {
                foreach (['', "Intro text\n\n"] as $intro) {
                    $rows[$prefix . $first . $intro] = [$first, $second, $prefix, $intro];
                }
            }
        }

        return $rows;
    }

    public function testParagraphImportKeepsInlineFormattingAndSeparateParagraphs(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("A **strong** phrase with [link](/target) and `code`.\n\nB plain.\n");

        self::assertSame("A *strong* phrase with [link](/target) and `code`.\n\nB plain.\n", $result->value);
        self::assertSame('markdown', $result->sourceFormat);
    }
}
