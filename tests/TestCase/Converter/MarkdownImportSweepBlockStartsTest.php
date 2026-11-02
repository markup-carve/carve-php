<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Block starts from the CommonMark import sweep (markup-carve/carve#4932 and
 * siblings): the expected HTML is commonmark.js 0.31.2's reading of the input.
 */
class MarkdownImportSweepBlockStartsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideImports(): array
    {
        return [
            'an HTML block takes a deeper quote line as content' => [
                "<div\n> x\n",
                "```=html\n<div\n> x\n```\n",
                "<div\n> x",
            ],
            'a quoted HTML block takes a deeper quote line as content' => [
                "> <div>\n> > x\n",
                "> ```=html\n> <div>\n> > x\n> ```\n",
                "<blockquote>\n  <div>\n> x\n</blockquote>",
            ],
            'a tab after a quote marker is measured from the marker' => [
                "> \t<div>\n> a\n",
                "> ```=html\n> \t<div>\n> a\n> ```\n",
                "<blockquote>\n  \t<div>\na\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('provideImports')]
    public function testTheImportReadsTheBlockAsCommonMarkDoes(string $markdown, string $carve, string $html): void
    {
        $written = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $written);
        $this->assertSame($html, trim((new CarveConverter())->convert($written)));
    }
}
