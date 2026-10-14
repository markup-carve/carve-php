<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotEmptyListItemTest extends TestCase
{
    #[DataProvider('sourceProvider')]
    public function testEmptyListItem(string $source, string $expected, string $html): void
    {
        $carve = (new DjotToCarve())->convert($source . "\n");
        self::assertSame($expected . "\n", $carve);
        $actual = preg_replace('/>\s+</', '><', trim((new CarveConverter())->convert($carve)));
        self::assertSame($html, $actual);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'between items' => ["- a\n-\n- c", "- a\n- +\n- c", '<ul><li>a</li><li></li><li>c</li></ul>'],
            'before an empty quote' => ["-\n>", "- +\n\n>", '<ul><li></li></ul><blockquote></blockquote>'],
            'before a one-column empty quote' => ["-\n >", "- +\n\n>", '<ul><li></li></ul><blockquote></blockquote>'],
            'marker-line child' => ['- -', '- - +', '<ul><li><ul><li></li></ul></li></ul>'],
            'marker-line child before a paragraph' => ["- 1.\ntext", "- 1. +\n\ntext", '<ul><li><ol><li></li></ol></li></ul><p>text</p>'],
            'marker-line child before an outer quote' => ["- 1.\n  > q", "- 1. +\n\n  \\> q", '<ul><li><ol><li></li></ol><p>&gt; q</p></li></ul>'],
            'dash' => ['-', '- +', '<ul><li></li></ul>'],
            'number' => ['1.', '1. +', '<ol><li></li></ol>'],
            'letter' => ['a.', 'a. +', '<ol type="a"><li></li></ol>'],
            'star' => ['*', '* +', '<ul><li></li></ul>'],
            'continuation marker' => ['+', '+', '<p>+</p>'],
            'before a paragraph' => ["-\na", "- +\n\na", '<ul><li></li></ul><p>a</p>'],
            'before a quote' => ["-\n> q", "- +\n\n> q", '<ul><li></li></ul><blockquote><p>q</p></blockquote>'],
            'before indented content' => ["-\n  a", "- +\n  a", '<ul><li>a</li></ul>'],
            'before a sibling' => ["-\n- b", "- +\n- b", '<ul><li></li><li>b</li></ul>'],
            'nested' => [
                "- a\n  -\n- c", "- a\n  - +\n- c",
                "<ul><li>a\n    <ul><li></li></ul></li><li>c</li></ul>",
            ],
            'quote' => ['> -', '> - +', '<blockquote><ul><li></li></ul></blockquote>'],
            'trailing spaces' => ['-  ', '- +', '<ul><li></li></ul>'],
            'paragraph' => ["a\n-", "a\n-", "<p>a\n-</p>"],
            'quote paragraph' => ["> a\n> -", "> a\n> -", "<blockquote><p>a\n-</p></blockquote>"],
            'thematic break' => ['---', '***', '<hr>'],
            'nonempty item' => ['- a', '- a', '<ul><li>a</li></ul>'],
            'inline code' => ['`-`', '`-`', '<p><code>-</code></p>'],
            'fenced code' => ["```\n-\n```", "```\n-\n```", "<pre><code>-\n</code></pre>"],
            'task-like item' => ['- [ ]', '- [ ] %%%%', '<ul class="task-list"><li><input type="checkbox" disabled></li></ul>'],
            'attribute-only item' => ['- {#x}', '- {#x}', '<ul><li></li></ul>'],
        ];
    }
}
