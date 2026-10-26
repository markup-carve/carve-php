<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownTableHyphenRunTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cases(): iterable
    {
        yield 'three hyphens in first cell' => ['|---|x|', '| \\-\\-\\- | x |', '<tr><td>---</td><td>x</td></tr>'];
        yield 'two hyphens in second cell' => ['|x|--|', '| x | \\-\\- |', '<tr><td>x</td><td>--</td></tr>'];
        yield 'four hyphens in first cell' => ['|----|x|', '| \\-\\-\\-\\- | x |', '<tr><td>----</td><td>x</td></tr>'];
    }

    #[DataProvider('cases')]
    public function testTableCellKeepsLiteralHyphens(string $row, string $expectedRow, string $expectedHtml): void
    {
        $source = "|a|b|\n|---|---|\n" . $row . "\n";
        $carve = (new MarkdownToCarve())->convert($source);
        self::assertStringContainsString($expectedRow, $carve);
        self::assertStringContainsString($expectedHtml, (new CarveConverter())->convert($carve));
    }
}
