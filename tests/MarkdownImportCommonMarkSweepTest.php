<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownImportCommonMarkSweepTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideImports(): array
    {
        return [
            'mention after underscore emphasis' => ["x _a_@b c\n", "x /a/\\@b c\n", '<p>x <em>a</em>@b c</p>'],
            'tag after underscore emphasis' => ["x _a_#b\n", "x /a/\\#b\n", '<p>x <em>a</em>#b</p>'],
            'space reference as emphasis' => ["*&#x20;*\n", "{/ /}\n", '<p><em> </em></p>'],
            'space reference opening strong' => ["**&#32;a**\n", "{* a*}\n", '<p><strong> a</strong></p>'],
            'space reference between words' => ["a*&#x20;*b\n", "a* *b\n", '<p>a* *b</p>'],
            'escaped backslash before an entity' => ["[](<\\\\&quot;>)\n", "[](\\\")\n", '<p><a href="\\&quot;"></a></p>'],
            'escaped backslash before punctuation' => ["[a](<a\\\\*>)\n", "[a](a\\*)\n", '<p><a href="a\\*">a</a></p>'],
            'escaped backslash before a space' => ["[a](<\\\\&#32;>)\n", "[a](\\%20)\n", '<p><a href="\\%20">a</a></p>'],
            'break between two lines of a tight item' => ["* _\n_**\n  ___\n  ^_\n", "* _\n  _**\n  ---\n  ^_\n", "<ul>\n  <li>_\n_**\n    <hr>\n    ^_\n  </li>\n</ul>"],
            'outer quote blank after an open fence' => ["> > ```\n> > code\n>\n> > text\n", "> > ```\n> > code\n> > ```\n>\n> > text\n", ''],
            'outer quote blank after a closed fence' => ["> > ```\n> > c\n> > ```\n>\n> t\n", "> > ```\n> > c\n> > ```\n>\n> t\n", ''],
        ];
    }

    #[DataProvider('provideImports')]
    public function testImport(string $markdown, string $carve, string $html): void
    {
        $converter = new MarkdownToCarve();
        self::assertSame($carve, $converter->convert($markdown));
        self::assertSame($carve, $converter->convertWithFidelityReport($markdown)->value);
        if ($html !== '') {
            self::assertSame($html, trim((new CarveConverter())->convert($carve)));
        }
    }

    public function testOuterQuoteHoldsBothInnerQuotes(): void
    {
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert("> > ```\n> > code\n>\n> > text\n"));

        self::assertStringStartsWith("<blockquote>\n  <blockquote>", $html);
        self::assertStringEndsWith("<blockquote><p>text</p></blockquote>\n</blockquote>", trim($html));
    }
}
