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
        yield 'double pair after letter' => ['## x"a"' . "\n", ['xa']];
        yield 'double pair after slash' => ['## a/"b"' . "\n", ['a-b']];
        yield 'double pair inside strong' => ['## *"a"*' . "\n", ['a']];
        yield 'single pair in link text' => ["## ['a'](u)\n", ['a']];
        yield 'single pair in span' => ["## {'a'}\n", ['a']];
        yield 'tab heading keeps following pairing' => ["##\t'a'\n\n## x 'b' y\n", ['a', 'x-b-y']];
        yield 'fenced heading text does not affect pairing' => ["```sh\n# install\n```\n\n## 'a'\n", ['a']];
        yield 'raw fenced heading text does not affect pairing' => ["```=html\n## 'x'\n```\n\n## 'a'\n", ['a']];
        yield 'single outside double pair' => ["## '\"a\"'\n", ['a']];
        yield 'double outside single pair' => ["## \"'a'\"\n", ['a']];
        yield 'two adjacent single pairs' => ["## 'a''b'\n", ['ab']];
        yield 'nested single pairs' => ["## ''a''\n", ['a']];
        yield 'nested single after space' => ["## 'a 'b' c'\n", ['a-b-c']];
        yield 'nested double after space' => ["## \"a \"b\" c\"\n", ['a-b-c']];
        yield 'unclosed outer single after space' => ["## 'a 'b c'\n", ["'a-b-c"]];
        yield 'autolink quote before pair' => ["## <http://x'y> 'b'\n", ["http:-x'y-b"]];
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
        yield 'punctuation before single opener' => ["## x]'a'\n", ["x-'a'"]];
        yield 'closer before letter' => ["## 'a'b\n", ['ab']];
        yield 'double closer before letter' => ['## "a"b' . "\n", ['ab']];
        yield 'single quote closes inside word' => ["## 'it's a'\n", ["its-a'"]];
        yield 'multiline paired heading' => ["## 'b\nc'\n", ['b-c']];
        yield 'multiline opening quote after line break' => ["## x\n'a'\n", ['x-a']];
        yield 'attribute before quoted heading' => ["{#q}\n## 'a'\n\n## 'b'\n", ['q', 'b']];
        yield 'class before quoted heading' => ["{.c}\n## 'a'\n\n## 'b'\n", ['a', 'b']];
        yield 'heading marker line continuation' => ["## a\n## 'b'\n", ['a-b']];
        yield 'attribute after heading' => ["## 'x'\n\n## 'a'\n{.c}\n## 'b'\n", ['x', 'a', 'b']];
        yield 'list after heading' => ["## 'a'\n- 'b'\n", ['a']];
        yield 'quote blank before heading' => ["> ## 'a'\n>\n> ## 'b'\n", ['a', 'b']];
        yield 'fence before heading' => ["```\nx\n```\n## 'a'\n", ['a']];
        yield 'div before heading' => ["::: d\n## 'a'\n::: \n", ['a']];
        yield 'table row before heading' => ["| t |\n## 'a'\n", ['a']];
        yield 'multiline attribute before heading' => ["{#q\n .c}\n## 'a'\n", ['q']];
        yield 'multiline key attribute before heading' => ["{#q\n title=\"x\"}\n## 'a'\n\n## 'b'\n", ['q', 'b']];
        yield 'quoted heading inside footnote' => ["## 'a'\n\nref[^n]\n\n[^n]: ## 'a'\n\n## 'a'\n", ['a', 'a-2', 'a-1']];
        yield 'different heading levels stay separate' => ["## 'a'\n### 'b'\n", ['a', 'b']];
        yield 'footnote definition and top-level heading stay separate' => ["ref[^n]\n\n[^n]: ## x\n## 'b'\n\n## 'c'\n", ['b', 'c', 'x']];
        yield 'quoted footnote and top-level heading stay separate' => ["ref[^n]\n\n[^n]: ## 'a'\n## 'b'\n\n## c\n", ['b', 'c', 'a']];
        yield 'indented heading line continues pairing' => ["## 'a\n  ## b'\n", ['a-b']];
        yield 'footnote heading line continues pairing' => ["ref[^n]\n\n[^n]: ## 'a'\n    ## 'b'\n\n## 'c'\n", ['c', 'a-b']];
        yield 'attribute heading remains counted with footnote' => ["{#q}\n## 'a'\n\nref[^n]\n\n[^n]: ## 'a'\n\n## 'a'\n", ['q', 'a-1', 'a']];
        yield 'heading in list marker stays counted' => ["- ## 'a'\n\n## 'b'\n", ['a', 'b']];
        yield 'heading after blockquote' => ["> x\n## 'a'\n\n## 'b'\n", ['a', 'b']];
        yield 'pipe text does not start a table' => ["x\n| t\n## 'a'\n\n## 'b'\n", ['b']];
        yield 'attribute fragment stays paragraph text' => ["x\n.y}\n## 'a'\n\n## 'b'\n", ['b']];
        yield 'crossing quote pairs' => ["## a 'b \"c' d\"\n", ['a-b-"c-d"']];
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
        preg_match_all('/<(?:section|h[1-6]) id="([^"]+)/', $html, $matches);

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
