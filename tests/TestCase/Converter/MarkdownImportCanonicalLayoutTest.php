<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownImportCanonicalLayoutTest extends TestCase
{
    public static function layouts(): iterable
    {
        yield 'heading followed by quoted paragraph' => ["> # Foo\n> bar\n", "> # Foo\n>\n> bar\n"];
        yield 'heading in a quoted item stays tight' => ["> - a\n>   # h\n>   b\n", "> - a\n>   # h\n>   b\n"];
        yield 'quoted blanks between heading and paragraph' => ["> # Head\n>\n>\n> paragraph\n", "> # Head\n>\n> paragraph\n"];
        yield 'empty inner quote before outer paragraph' => ["> > a\n> >\n> c\n", "> > a\n>\n> c\n"];
        yield 'empty inner quote at end' => ["> a\n> >\n", "> a\n>\n> >\n"];
        yield 'lazy quoted paragraph' => ["> # Foo\n> bar\nbaz\n", "> # Foo\n>\n> bar\n> baz\n"];
        yield 'empty quote' => [">\n>  \n> \n", ">\n"];
        yield 'quoted blank before paragraph' => ["> bar\n>\nbaz\n", "> bar\n\nbaz\n"];
        yield 'quoted blanks before a sibling list' => ["> paragraph\n>\n>\n- sibling\n", "> paragraph\n\n- sibling\n"];
        yield 'quoted fence followed by code' => ["> ```\nfoo\n```\n", "> ```\n> ```\n\nfoo\n\n```\n```\n"];
        yield 'quoted fence followed by indented code' => ["> ~~~\n> body\n> ~~~\n    code\n", "> ```\n> body\n> ```\n\n```\ncode\n```\n"];
        yield 'padded list with continuation' => [" -    one\n\n      two\n", "- one\n\n  two\n"];
        yield 'padded list with incidental continuation indent' => ["1.  first\n\n       second\n", "1. first\n\n   second\n"];
        yield 'blank indented code payload' => ["- Foo\n\n      bar\n\n\n      baz\n", "{loose}\n- Foo\n\n  ```\n  bar\n\n\n  baz\n  ```\n"];
        yield 'indented code payload with spaces' => ["- Foo\n\n      bar\n        \n      baz\n", "{loose}\n- Foo\n\n  ```\n  bar\n    \n  baz\n  ```\n"];
        yield 'lazy quote inside quoted item' => ["> 1. > Blockquote\ncontinued here.\n", "> 1. > Blockquote\n>    > continued here.\n"];
        yield 'explicit tail after lazy quoted item' => ["> - > inner\ncontinued\n> tail\n", "> - > inner\n>   > continued\n>   > tail\n"];
        yield 'quoted heading closes its lazy paragraph' => ["> - > # h\n>   b\n", "> - > # h\n>   b\n"];
        yield 'sibling item closes nested quote' => ["> 1. > a\n> 2. b\n> c\n", "> 1. > a\n> 2. b\n>    c\n"];
        yield 'empty quote before sibling item' => ["* a\n  > b\n  >\n* c\n", "* a\n  > b\n* c\n"];
        yield 'sole empty quote inside item' => ["* a\n  >\n* c\n", "* a\n  >\n* c\n"];
        yield 'empty heading fallback' => ["## \n#\n### ###\n", "```=html\n<h2></h2>\n```\n\n```=html\n<h1></h1>\n```\n\n```=html\n<h3></h3>\n```\n"];
    }

    #[DataProvider('layouts')]
    public function testImportWritesCanonicalLayout(string $markdown, string $expected, bool $fixedPoint = true): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        self::assertSame($expected, $imported);
        if ($fixedPoint) {
            self::assertSame($imported, CarveConverter::toCarve($imported));
        } else {
            self::assertNotSame($imported, CarveConverter::toCarve($imported));
        }
        self::assertSame(str_ends_with($markdown, "\n"), str_ends_with($imported, "\n"));
    }

    public function testEmptyInputStaysEmpty(): void
    {
        self::assertSame('', (new MarkdownToCarve())->convert(''));
    }

    public function testLazyNestedQuoteKeepsDirectiveTextInParagraph(): void
    {
        $imported = (new MarkdownToCarve())->convert("> 1. > a\n::: b\n");
        $converter = CarveConverter::create();
        $html = $converter->render($converter->parse($imported));

        self::assertSame("> 1. > a\n>    > \\::: b\n", $imported);
        self::assertStringContainsString("a\n::: b", $html);
        self::assertStringNotContainsString('<div', $html);
    }
}
