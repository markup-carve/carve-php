<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndentedTableRowAfterDelimiterTest extends TestCase
{
    use RuleATodo;

    #[DataProvider('sources')]
    public function testDefaultConverterEndsTableBeforeIndentedRow(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source));
    }

    public static function sources(): iterable
    {
        yield 'one column' => [
            "|a|\n|---|\n|b|\n |c|\n",
            "<table>\n  <thead>\n    <tr><th scope=\"col\">a</th></tr>\n  </thead>\n  <tbody>\n    <tr><td>b</td></tr>\n  </tbody>\n</table>\n<p>|c|</p>\n",
        ];

        yield 'two columns' => [
            "| a | b |\n|---|---|\n| c | d |\n | x | y |\n",
            "<table>\n  <thead>\n    <tr><th scope=\"col\">a</th><th scope=\"col\">b</th></tr>\n  </thead>\n  <tbody>\n    <tr><td>c</td><td>d</td></tr>\n  </tbody>\n</table>\n<p>| x | y |</p>\n",
        ];
    }
}
