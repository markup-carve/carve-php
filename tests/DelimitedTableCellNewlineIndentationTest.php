<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DelimitedTableCellNewlineIndentationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cells(): iterable
    {
        yield 'header cell' => ["::: box\n{table}\n```csv\n\"one\ntwo\"\n```\n:::\n", "<th scope=\"col\">one\ntwo</th>"];
        yield 'data cell' => ["::: box\n{table}\n```csv\nhead\n\"one\ntwo\"\n```\n:::\n", "<td>one\ntwo</td>"];
        yield 'list with authored payload space' => ["- item\n\n  {table}\n  ```csv\n  \"one\n   two\"\n  ```\n", "<th scope=\"col\">one\n two</th>"];
    }

    #[DataProvider('cells')]
    public function testContainerIndentationDoesNotEnterCellText(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString($expected, $html);
    }
}
