<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotSmartQuoteHeadingIdsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function headingIds(): iterable
    {
        yield 'paired single with slash' => ["## 'a/'\n", ['a']];
        yield 'paired single' => ["## 'a'\n", ['a']];
        yield 'paired double with slash' => ['## "a/b"' . "\n", ['a-b']];
        yield 'paired double' => ['## "a"' . "\n", ['a']];
        yield 'paired single inside heading' => ["## a 'b' c\n", ['a-b-c']];
        yield 'paired double inside heading' => ['## a "b" c' . "\n", ['a-b-c']];
        yield 'paired and apostrophe' => ["## it's 'a'\n", ["it's-a"]];
        yield 'quotes inside code' => ['## `"a"`' . "\n", ['"a"']];
        yield 'quotes in link text' => ['## ["a"](u)' . "\n", ['a']];
        yield 'authored curly before paired quote' => ['## “x” "a"' . "\n", ['“x”-a']];
        yield 'quote after slash stays literal' => ["## a/'b'\n", ["a-'b'"]];
        yield 'quote after strong opener stays literal' => ["## *'a'*\n", ["'a'"]];
        yield 'quote after emphasis opener stays literal' => ["## _'a'_ b\n", ["'a'-b"]];
        yield 'empty quote pair stays literal' => ["## '' x\n", ["''-x"]];
        yield 'quote after unicode letter stays literal' => ["## é'a'\n", ["é'a'"]];
        yield 'destination quote is not counted' => ["## [x](u'v) 'a'\n", ['x-a']];
        yield 'attribute quote remains counted' => ["## x{title='t'} 'a'\n", ["x-title-'t'-a"]];
        yield 'code quote before pair remains counted' => ["## `'` 'a'\n", ["'-a"]];
        yield 'authored curly single' => ["## ‘a’\n", ['‘a’']];
        yield 'authored curly double' => ["## “a”\n", ['“a”']];
        yield 'unmatched single' => ["## a/'\n", ["a-'"]];
        yield 'duplicate paired quotes' => ["## 'a/'\n\n## 'a/'\n", ['a', 'a-1']];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('headingIds')]
    public function testHeadingIdsFollowDjotQuotePairing(string $source, array $expected): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));
        preg_match_all('/<section id="([^"]+)/', $html, $matches);

        self::assertSame($expected, array_map(static fn (string $id): string => html_entity_decode($id, ENT_QUOTES | ENT_HTML5), $matches[1]));
    }

    public function testUnmatchedSingleHeadingTextAndId(): void
    {
        $source = "## a/'\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="a-&apos;">', $html);
        self::assertStringContainsString('<h2>a/’</h2>', $html);
    }

    public function testPairedHeadingTextStaysPaired(): void
    {
        $source = "## 'a/'\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="a">', $html);
        self::assertStringContainsString('<h2>‘a/’</h2>', $html);
    }

    public function testExplicitHeadingIdKeepsClosingSingleQuote(): void
    {
        $source = "{#keep}\n## a/'\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="keep">', $html);
        self::assertStringContainsString('<h2>a/’</h2>', $html);
    }

    public function testReferenceToDuplicatePairedHeading(): void
    {
        $source = "## 'a/'\n\n## 'a/'\n\n[go](#a-1)\n";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('<section id="a-1">', $html);
        self::assertStringContainsString('href="#a-1"', $html);
    }
}
