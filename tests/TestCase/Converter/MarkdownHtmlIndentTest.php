<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownHtmlIndentTest extends TestCase
{
    public function testOpeningIndentDoesNotIndentEveryHtmlLine(): void
    {
        foreach ([' ', '  ', '   '] as $indent) {
            $source = $indent . "<div>\n  *hello*\n         <foo><a>\n";
            $converted = (new MarkdownToCarve())->convert($source);
            $this->assertSame(rtrim($source, "\n"), rtrim((new CarveConverter())->convert($converted), "\n"));
        }
    }

    public function testAnyRawTextClosingTagEndsTheHtmlBlock(): void
    {
        $converted = (new MarkdownToCarve())->convert("<script>\nx\n</style>\nafter");
        $this->assertSame("<script>\nx\n</style>\n<p>after</p>", rtrim((new CarveConverter())->convert($converted), "\n"));
    }

    public function testContainerIndentIsSeparateFromHtmlContent(): void
    {
        foreach (
            [
                [">  <div>\n>  x", "> ```=html\n>  <div>\n>  x\n> ```"],
                ["- a\n\n   <div>\n   x", "{loose}\n- a\n\n  ```=html\n   <div>\n   x\n  ```"],
            ] as [$source, $expected]
        ) {
            $converted = (new MarkdownToCarve())->convert($source);
            $this->assertSame($expected, rtrim($converted, "\n"));
            $this->assertStringNotContainsString('<code>', (new CarveConverter())->convert($converted));
        }
    }

    public function testQuoteTabLeavesItsExtraColumnsInHtml(): void
    {
        $converted = (new MarkdownToCarve())->convert(">\t<div>\n>\tx");
        $this->assertSame("> ```=html\n>   <div>\n>   x\n> ```", rtrim($converted, "\n"));
    }

    public function testQuotedBlocksEndThePreviousList(): void
    {
        foreach (["```\nc\n```", '---'] as $block) {
            $source = "> - a\n>\n> " . str_replace("\n", "\n> ", $block) . "\n>\n>   <div>\n>   x";
            $converted = (new MarkdownToCarve())->convert($source);
            $this->assertStringContainsString("> ```=html\n>   <div>\n>   x\n> ```", $converted);
        }
    }
}
