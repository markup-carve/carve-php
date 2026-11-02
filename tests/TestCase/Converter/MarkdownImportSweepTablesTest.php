<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lines around a GFM table from the CommonMark import sweep
 * (markup-carve/carve#4928): the expected HTML is cmark-gfm's reading of the
 * input.
 */
class MarkdownImportSweepTablesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideImports(): array
    {
        $table = "<table>\n  <thead>\n    <tr><th scope=\"col\">a</th><th scope=\"col\">b</th></tr>\n  </thead>\n";

        return [
            'a bare marker after a table opens an empty item' => [
                "a | b\n--|--\n1 | 2\n*\n",
                "|= a |= b |\n| 1 | 2 |\n\n* {empty}\n",
                $table . "  <tbody>\n    <tr><td>1</td><td>2</td></tr>\n  </tbody>\n</table>\n<ul>\n  <li></li>\n</ul>",
            ],
            'a line four columns in after a table is code' => [
                "  | a | b |\n  | - | - |\n    | c | d |\n",
                "|= a |= b |\n\n```\n| c | d |\n```\n",
                $table . "</table>\n<pre><code>| c | d |\n</code></pre>",
            ],
            'a delimiter row that opens a bullet is a list item' => [
                "a | b\\\n- | -\n1 | 2\n",
                "a | b\\\\\n\n- | -\n  1 | 2\n",
                "<p>a | b\\</p>\n<ul>\n  <li>| -\n1 | 2</li>\n</ul>",
            ],
            'a trailing backslash before a table is text' => [
                "a\\\n| a | b |\n|---|---|\n",
                "a\\\\\n\n|= a |= b |\n",
                "<p>a\\</p>\n" . $table . '</table>',
            ],
            'trailing spaces before a table break nothing' => [
                "a  \n| a | b |\n|---|---|\n",
                "a\n\n|= a |= b |\n",
                "<p>a</p>\n" . $table . '</table>',
            ],
            'a tab after an item column continues the table' => [
                "- a | b\n  --|--\n  1 | 2\n  \tc\n",
                "- |= a |= b |\n  | 1 | 2 |\n  | c | |\n",
                "<ul>\n  <li>\n    <table>\n      <thead>\n        <tr><th scope=\"col\">a</th><th scope=\"col\">b</th></tr>\n      </thead>\n      <tbody>\n        <tr><td>1</td><td>2</td></tr>\n        <tr><td>c</td><td></td></tr>\n      </tbody>\n    </table>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideImports')]
    public function testTheImportReadsTheTableAsCmarkGfmDoes(string $markdown, string $carve, string $html): void
    {
        $written = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $written);
        $this->assertSame($html, trim((new CarveConverter())->convert($written)));
    }
}
