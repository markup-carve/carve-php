<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Indented code inside a block quote imports as a fence in the quote, as it
 * does at the top level and in list items. Left indented, Carve read it as a
 * paragraph.
 */
class AMarkdownQuotedIndentedCodeIsAFenceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'alone in a quote' => [">     code\n", "> ```\n> code\n> ```\n"],
            'two lines' => [">     a\n>     b\n", "> ```\n> a\n> b\n> ```\n"],
            'a blank line inside it' => [">     a\n>\n>     b\n", "> ```\n> a\n>\n> b\n> ```\n"],
            'backticks in the code' => [">     ```\n>     x\n", "> ````\n> ```\n> x\n> ````\n"],
            'text after it in the quote' => [">     code\n> text\n", "> ```\n> code\n> ```\n>\n> text\n"],
            'text after the quote' => [">     code\ntext\n", "> ```\n> code\n> ```\n\ntext\n"],
            'a tab inside the code' => [">     \tfoo\n", "> ```\n> \tfoo\n> ```\n"],
            'an outer quote line after nested code' => ["> >     > inner\n> text\n", "> > ```\n> > > inner\n> > ```\n>\n> text\n"],
            'indented code after the quote' => [">     code\n    next\n", "> ```\n> code\n> ```\n\n```\nnext\n```\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheCodeIsWrittenAsAFence(string $markdown, string $carve): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rendered(): array
    {
        return [
            'after a paragraph and a blank' => ["> a\n>\n>     code\n", "<blockquote>\n  <p>a</p>\n  <pre><code>code\n</code></pre>\n</blockquote>\n"],
            'in a nested quote' => ["> > a\n> >\n> >     code\n", "<blockquote>\n  <blockquote>\n    <p>a</p>\n    <pre><code>code\n</code></pre>\n  </blockquote>\n</blockquote>\n"],
            'in an item the quote holds' => ["> - a\n>\n>       code\n", "<blockquote>\n  <ul>\n    <li><p>a</p>\n      <pre><code>code\n</code></pre>\n    </li>\n  </ul>\n</blockquote>\n"],
            'a nested quote opened under a paragraph' => ["> text\n> >     code\n", "<blockquote>\n  <p>text</p>\n  <blockquote>\n    <pre><code>code\n</code></pre>\n  </blockquote>\n</blockquote>\n"],
            'lazy in a nested quote paragraph' => ["> > a\n>     code\n", "<blockquote>\n  <blockquote><p>a\ncode</p></blockquote>\n</blockquote>\n"],
            'a tab two columns past the marker' => ["> \tx\n", "<blockquote><p>x</p></blockquote>\n"],
            'a tab-padded item the quote holds' => [">\t- a\n>\n>\t\tcode\n", "<blockquote>\n  <ul>\n    <li><p>a</p>\n      <p>code</p>\n    </li>\n  </ul>\n</blockquote>\n"],
            'a quote line holding only spaces after it' => [">     code\n>   \n> text\n", "<blockquote>\n  <pre><code>code\n</code></pre>\n  <p>text</p>\n</blockquote>\n"],
            'a quote line holding only spaces between paragraphs' => ["> a\n>   \n> b\n", "<blockquote>\n  <p>a</p>\n  <p>b</p>\n</blockquote>\n"],
            'a quote an item holds stays in the item' => ["- a\n\n  >     code\n", "<ul>\n  <li><p>a</p>\n    <blockquote>\n      <pre><code>code\n</code></pre>\n    </blockquote>\n  </li>\n</ul>\n"],
            'after an earlier tab outside any item' => ["> \tx\n>\n>     *code*\n", "<blockquote>\n  <p>x</p>\n  <pre><code>*code*\n</code></pre>\n</blockquote>\n"],
            'under a paragraph it continues' => ["> text\n>     not code\n", "<blockquote><p>text\nnot code</p></blockquote>\n"],
        ];
    }

    #[DataProvider('rendered')]
    public function testTheQuoteRendersTheCode(string $markdown, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown)));
    }
}
