<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownTableBackslashRunsTest extends TestCase
{
    #[DataProvider('runs')]
    public function testBackslashRunBeforeAnEscapedPipeIsAssessedCompletely(string $source): void
    {
        $codes = array_column((new MarkdownToCarve())->convertWithFidelityReport($source)->report()['diagnostics'], 'code');
        self::assertNotContains('fidelity-unverified', $codes);
        self::assertContains('markdown-table-cell', $codes);
    }

    /**
     * cmark-gfm drops exactly one backslash before a pipe, whatever the run length.
     *
     * @return iterable<string, array{string}>
     */
    public static function runs(): iterable
    {
        $shapes = [
            'text' => '| x%s|y | z |',
            'autolink' => '| <https://e.com/a%s|b> | z |',
            'bare URL' => '| https://e.com/a%s|b | z |',
            'link destination' => '| [l](https://e.com/a%s|b) | z |',
            'code span' => '| `x%s|y` | z |',
        ];
        foreach ($shapes as $name => $shape) {
            foreach (range(1, 6) as $count) {
                yield $name . ', ' . $count . ' backslashes' => [sprintf($shape, str_repeat('\\', $count)) . "\n|---|---|\n"];
            }
        }
    }
}
