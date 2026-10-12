<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `carve fmt` writes every reference definition at the end of the document, one
 * line each, so the import does too. Joined onto one line, a destination or
 * title on the next line stays part of the definition.
 */
class AMarkdownReferenceDefinitionGoesToTheEndTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'between paragraphs' => ["a [x]\n\n[x]: /u\n\nb\n", "a [x][]\n\nb\n\n[x]: /u\n", "<p>a <a href=\"/u\">x</a></p>\n<p>b</p>\n"],
            'before a paragraph' => ["[x]: /u\nb [x]\n", "b [x][]\n\n[x]: /u\n", "<p>b <a href=\"/u\">x</a></p>\n"],
            'two in source order' => ["[b]: /2\n[a]: /1\n\n[a] [b]\n", "[a][] [b][]\n\n[b]: /2\n\n[a]: /1\n", "<p><a href=\"/1\">a</a> <a href=\"/2\">b</a></p>\n"],
            'in a quote' => ["> [x]: /u\n> b [x]\n", "> b [x][]\n\n[x]: /u\n", "<blockquote><p>b <a href=\"/u\">x</a></p></blockquote>\n"],
            'as an item' => ["- [x]: /u\n- b [x]\n", "- %%\n- b [x][]\n\n[x]: /u\n", "<ul>\n  <li></li>\n  <li>b <a href=\"/u\">x</a></li>\n</ul>\n"],
            'as an item with a lazy line' => ["- [x]: /u\nb [x]\n", "- b [x][]\n\n[x]: /u\n", "<ul>\n  <li>b <a href=\"/u\">x</a></li>\n</ul>\n"],
            'in a quote with a lazy line' => ["> [x]: /u\nb [x]\n", "> b [x][]\n\n[x]: /u\n", "<blockquote><p>b <a href=\"/u\">x</a></p></blockquote>\n"],
            'two in an item' => ["- [x]: /u\n  [y]: /v\n\n[x] [y]\n", "- %%\n\n[x][] [y][]\n\n[x]: /u\n\n[y]: /v\n", "<ul>\n  <li></li>\n</ul>\n<p><a href=\"/u\">x</a> <a href=\"/v\">y</a></p>\n"],
            'a repeated label' => ["[x]: /1\n[X]: /2\n\n[x]\n", "[x][]\n\n[x]: /1\n", "<p><a href=\"/1\">x</a></p>\n"],
            'in an item' => ["- a [x]\n\n  [x]: /u\n", "- a [x][]\n\n[x]: /u\n", "<ul>\n  <li>a <a href=\"/u\">x</a></li>\n</ul>\n"],
            'a destination on the next line' => ["[x]:\n/u\nb [x]\n", "b [x][]\n\n[x]: /u\n", "<p>b <a href=\"/u\">x</a></p>\n"],
            'a title on the next line' => ["[x]: /u\n\"t\"\nb [x]\n", "b [x][]\n\n[x]: /u \"t\"\n", "<p>b <a href=\"/u\" title=\"t\">x</a></p>\n"],
            'before a fence' => ["[x]: /u\n\n```\nc\n```\n", "```\nc\n```\n\n[x]: /u\n", "<pre><code>c\n</code></pre>\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheDefinitionIsWrittenLast(string $markdown, string $carve, string $html): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($html, (new CarveConverter())->convert($imported));
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testAQuoteHoldingOnlyADefinitionStaysAQuote(): void
    {
        $imported = (new MarkdownToCarve())->convert("a\n\n> [x]: /u\n\nb\n");

        $this->assertSame("a\n\n>\n\nb\n\n[x]: /u\n", $imported);
        $this->assertSame("<p>a</p>\n<blockquote>\n\n</blockquote>\n<p>b</p>\n", (new CarveConverter())->convert($imported));
    }

    public function testADefinitionPartingTwoListsStaysBetweenThem(): void
    {
        $imported = (new MarkdownToCarve())->convert("- a\n\n[x]: /u\n- b [x]\n");

        $this->assertSame("<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b <a href=\"/u\">x</a></li>\n</ul>\n", (new CarveConverter())->convert($imported));
    }

    public function testALineWithNoDestinationClaimsNoLabel(): void
    {
        $imported = (new MarkdownToCarve())->convert("[x]:\n\n[x]: /v\n\n[x]\n");

        $this->assertSame("<p><a href=\"/v\">x</a>:</p>\n<p><a href=\"/v\">x</a></p>\n", (new CarveConverter())->convert($imported));
    }

    public function testADefinitionOnANestedItemsMarkerLineStaysADefinition(): void
    {
        $imported = (new MarkdownToCarve())->convert("- a\n  - [x]: /u\n- b [x]\n");

        $this->assertSame("- a\n  - [x]: /u\n- b [x][]\n", $imported);
        $this->assertSame(
            "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n  <li>b <a href=\"/u\">x</a></li>\n</ul>\n",
            (new CarveConverter())->convert($imported),
        );
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testAnItemHoldingOnlyAnEmptyDestinationDefinitionStaysAnItem(): void
    {
        $imported = (new MarkdownToCarve())->convert("- [x]: <>\n- b\n");

        $this->assertSame("- %%\n- b\n", $imported);
        $this->assertSame("<ul>\n  <li></li>\n  <li>b</li>\n</ul>\n", (new CarveConverter())->convert($imported));
    }

    public function testAHeadingAfterAnEmptiedDefinitionItemStaysOutsideTheItem(): void
    {
        $imported = (new MarkdownToCarve())->convert("- [x]: /u\n# H\n");

        $this->assertSame("- %%\n\n# H\n\n[x]: /u\n", $imported);
        $this->assertSame(
            "<ul>\n  <li></li>\n</ul>\n<section id=\"H\">\n  <h1>H</h1>\n</section>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    public function testAnEmptiedDefinitionInsideAQuotedListItemKeepsItsMarker(): void
    {
        $imported = (new MarkdownToCarve())->convert("- a\n  > - [x]: /u\n  > - b\n");

        $this->assertSame("- a\n  > - %%\n  > - b\n\n[x]: /u\n", $imported);
        $this->assertSame(
            "<ul>\n  <li>a\n    <blockquote>\n      <ul>\n        <li></li>\n        <li>b</li>\n      </ul>\n    </blockquote>\n  </li>\n</ul>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    public function testADefinitionClosingAnItemsFenceLeavesTwoCodeBlocks(): void
    {
        $imported = (new MarkdownToCarve())->convert("- ```\n[b]: /2 \"t\"\n  ```\n");

        $this->assertSame("- ```\n  ```\n\n```\n```\n\n[b]: /2 \"t\"\n", $imported);
        $this->assertSame(
            "<ul>\n  <li>\n    <pre><code></code></pre>\n  </li>\n</ul>\n<pre><code></code></pre>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    public function testALazyLineNamingADefinedLabelIsAReferenceLink(): void
    {
        $imported = (new MarkdownToCarve())->convert("> - [c]: <x y>\n> q [b]\n[c]: <x y>\n");

        $this->assertSame("> - q [b]\n> [c][]: `<x y>`{=html}\n\n[c]: x%20y\n", $imported);
        $this->assertSame(
            "<blockquote>\n  <ul>\n    <li>q [b]\n<a href=\"x%20y\">c</a>: <x y></li>\n  </ul>\n</blockquote>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    public function testAFootnoteGoesToTheEndAheadOfTheDefinitions(): void
    {
        $this->assertSame("a[^1]\n\nb\n\n[^1]: note\n", (new MarkdownToCarve())->convert("a[^1]\n\n[^1]: note\n\nb\n"));
        $this->assertSame(
            "a[^1] [x][]\n\nb\n\n[^1]: note\n\n[x]: /u\n",
            (new MarkdownToCarve())->convert("a[^1] [x]\n\n[^1]: note\n\n[x]: /u\n\nb\n"),
        );
    }

    public function testAFootnoteBodyMovesAsOneBlock(): void
    {
        $imported = (new MarkdownToCarve())->convert("a[^1]\n\n[^1]: note\n    more\n\nb\n");

        $this->assertSame("a[^1]\n\nb\n\n[^1]: note\n  more\n", $imported);
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testAFootnoteWhoseBodyHoldsABlockMovesAsOneBlock(): void
    {
        $this->assertSame(
            "a[^1]\n\nb\n\n[^1]: note\n\n  code\n",
            (new MarkdownToCarve())->convert("a[^1]\n\n[^1]: note\n\n    code\n\nb\n"),
        );
    }

    public function testTwoFootnotesInARowStayTwo(): void
    {
        $this->assertSame(
            "a[^1][^2]\n\n[^1]: one\n\n[^2]: two\n",
            (new MarkdownToCarve())->convert("a[^1][^2]\n\n[^1]: one\n[^2]: two\n"),
        );
    }

    public function testEmptyDefinitionsRetainListBoundariesAndReportTheLinkLoss(): void
    {
        foreach (['', '> '] as $prefix) {
            $markdown = $prefix . "- a\n" . rtrim($prefix) . "\n" . $prefix . "[r]: <>\n" . rtrim($prefix) . "\n" . $prefix . "- b\n\n[t][r]";
            $migration = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
            $expected = "<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b</li>\n</ul>\n<p>t</p>\n";
            if ($prefix !== '') {
                $expected = "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n  <ul>\n    <li>b</li>\n  </ul>\n</blockquote>\n<p>t</p>\n";
            }
            self::assertSame($expected, (new CarveConverter())->convert($migration->value));
            self::assertSame($expected, (new CarveConverter())->convert(CarveConverter::toCarve($migration->value)));
            $losses = array_filter($migration->diagnostics, static fn ($row) => $row->code === 'structure-unspellable');
            self::assertCount(1, $losses);
            self::assertSame('exact', array_values($losses)[0]->confidence);
        }
    }

    public function testConsecutiveDefinitionsAfterALazyListLineKeepTheListsSeparate(): void
    {
        foreach (["[^n]: note\n\n[r]: <>", "[^n]: note\n\n[^m]: other", "[r]: <>\n\n[^n]: note"] as $definitions) {
            $source = "- a\nlazy\n\n" . $definitions . "\n\n- b\n\nx[^n] y[^m] [t][r]";
            $written = (new MarkdownToCarve())->convert($source);
            $expected = "<ul>\n  <li>a\nlazy</li>\n</ul>\n<ul>\n  <li>b</li>\n</ul>\n";
            self::assertStringStartsWith($expected, (new CarveConverter())->convert($written));
            self::assertStringStartsWith($expected, (new CarveConverter())->convert(CarveConverter::toCarve($written)));
        }
    }

    public function testMovingFootnotesKeepsOrderedStartsAndParagraphBoundaries(): void
    {
        foreach ([1, 5] as $start) {
            $written = (new MarkdownToCarve())->convert("1. a\n\n[^n]: note\n\n" . $start . ". b\n\ntext[^n]");
            $second = $start === 1 ? '<ol>' : '<ol start="5">';
            $expected = "<ol>\n  <li>a</li>\n</ol>\n" . $second . "\n  <li>b</li>\n</ol>\n";
            self::assertStringStartsWith($expected, (new CarveConverter())->convert($written));
            self::assertStringStartsWith($expected, (new CarveConverter())->convert(CarveConverter::toCarve($written)));
        }
        $written = (new MarkdownToCarve())->convert("- a\n[^n]: note\n\n- b\n\nx[^n]");
        self::assertStringStartsWith("<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b</li>\n</ul>\n", (new CarveConverter())->convert($written));
    }

    public function testAFootnoteAfterAQuoteUsesItsOwnIndentToCloseTheList(): void
    {
        foreach ([1, 2, 3] as $padding) {
            $source = "- a\n  > " . str_repeat(' ', $padding) . "x\n[^n]: note\n- b\n\nt[^n]";
            $written = (new MarkdownToCarve())->convert($source);
            foreach ([$written, CarveConverter::toCarve($written)] as $rendered) {
                $html = (new CarveConverter())->convert($rendered);
                self::assertSame(2, substr_count($html, '<ul>'));
                self::assertStringContainsString('<blockquote>', $html);
                self::assertStringContainsString('<p>x</p>', $html);
            }
        }
    }

    public function testMovingAFootnoteDoesNotJoinTheListsAroundIt(): void
    {
        $written = (new MarkdownToCarve())->convert("- a\n\n[^n]: note\n\n- b\n\ntext[^n]");
        $expected = "<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b</li>\n</ul>\n";
        self::assertStringStartsWith($expected, (new CarveConverter())->convert($written));
        self::assertStringStartsWith($expected, (new CarveConverter())->convert(CarveConverter::toCarve($written)));
    }

    public function testDefinitionsInsideAnOuterItemKeepOneLooseList(): void
    {
        $expected = "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>x</li>\n    </ul>\n  </li>\n  <li><p>b</p></li>\n</ul>\n";
        foreach (['[r]: <>', '[x\\]y]: /u', '[^n]: note'] as $definition) {
            $written = (new MarkdownToCarve())->convert("- a\n  - x\n\n  " . $definition . "\n\n- b\n\n[t][r] text[^n]");
            self::assertStringStartsWith($expected, (new CarveConverter())->convert($written));
            self::assertStringStartsWith($expected, (new CarveConverter())->convert(CarveConverter::toCarve($written)));
        }
    }

    public function testAQuotedDefinitionOutsideTheItemSeparatesItsLists(): void
    {
        $migration = (new MarkdownToCarve())->convertWithFidelityReport("> - a\n>\n>  [r]: <>\n>\n> - b\n\n[t][r]");
        $expected = "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n  <ul>\n    <li>b</li>\n  </ul>\n</blockquote>\n<p>t</p>\n";
        self::assertSame($expected, (new CarveConverter())->convert($migration->value));
        self::assertSame($expected, (new CarveConverter())->convert(CarveConverter::toCarve($migration->value)));
    }

    public function testMarkerColumnsDetermineWhetherAnEmptyDefinitionLeavesTheList(): void
    {
        foreach (["-\ta", ' - a', '  - a', '   - a'] as $head) {
            $written = (new MarkdownToCarve())->convert($head . "\n\n[r]: <>\n\n - b\n\n[t][r]");
            $expected = "<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b</li>\n</ul>\n<p>t</p>\n";
            self::assertSame($expected, (new CarveConverter())->convert($written));
            self::assertSame($expected, (new CarveConverter())->convert(CarveConverter::toCarve($written)));
        }
        $written = (new MarkdownToCarve())->convert("-     code\n\n  [r]: <>\n\n- b\n\n[t][r]");
        $expected = "<ul>\n  <li>\n    <pre><code>code\n</code></pre>\n  </li>\n  <li><p>b</p></li>\n</ul>\n<p>t</p>\n";
        self::assertSame($expected, (new CarveConverter())->convert($written));
        self::assertSame($expected, (new CarveConverter())->convert(CarveConverter::toCarve($written)));
    }

    public function testSeparateListsSurviveConversionInsideABlockFootnote(): void
    {
        $written = (new MarkdownToCarve())->convert("text[^n]\n\n[^n]: intro\n\n    - a\n\n    [r]: <>\n\n    - b");
        $expected = "      <ul>\n        <li>a</li>\n      </ul>\n      <ul>\n        <li>b</li>\n      </ul>";
        self::assertStringContainsString($expected, (new CarveConverter())->convert($written));
        self::assertStringContainsString($expected, (new CarveConverter())->convert(CarveConverter::toCarve($written)));
    }

    public function testAnEmptyMarkerUsesOneColumnOfPadding(): void
    {
        $written = (new MarkdownToCarve())->convert("-   \n  x\n\n  [r]: <>\n\n- b\n\n[t][r]");
        $expected = "<ul>\n  <li><p>x</p></li>\n  <li><p>b</p></li>\n</ul>\n<p>t</p>\n";
        self::assertSame($expected, (new CarveConverter())->convert($written));
        self::assertSame($expected, (new CarveConverter())->convert(CarveConverter::toCarve($written)));
    }

    public function testStaircaseMarkerIndentationDoesNotInventAnAncestor(): void
    {
        $written = (new MarkdownToCarve())->convert("- a\n - b\n\n  [r]: <>\n\n- c\n\n[t][r]");
        $expected = "<ul>\n  <li>a</li>\n  <li>b</li>\n</ul>\n<ul>\n  <li>c</li>\n</ul>\n<p>t</p>\n";
        self::assertSame($expected, (new CarveConverter())->convert($written));
        self::assertSame($expected, (new CarveConverter())->convert(CarveConverter::toCarve($written)));
    }

    public function testCompactQuoteMarkersPreserveTheListBoundary(): void
    {
        $written = (new MarkdownToCarve())->convert(">- a\n>\n> [r]: <>\n>\n>- b");
        $expected = "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n  <ul>\n    <li>b</li>\n  </ul>\n</blockquote>\n";
        self::assertSame($expected, (new CarveConverter())->convert($written));
        self::assertSame($expected, (new CarveConverter())->convert(CarveConverter::toCarve($written)));
    }

    public function testAnOrderedMarkerOutsideTheItemKeepsThreeLists(): void
    {
        $migration = (new MarkdownToCarve())->convertWithFidelityReport('- a
2019. was a year

  [r]: <>

- b

[t][r]');
        $expected = '<ul>
  <li>a</li>
</ul>
<ol start="2019">
  <li>was a year</li>
</ol>
<ul>
  <li>b</li>
</ul>
<p>t</p>
';
        self::assertSame($expected, (new CarveConverter())->convert($migration->value));
        self::assertSame($expected, (new CarveConverter())->convert(CarveConverter::toCarve($migration->value)));
        $losses = array_filter($migration->diagnostics, static fn ($row) => $row->code === 'structure-unspellable');
        self::assertCount(1, $losses);
        self::assertSame('exact', array_values($losses)[0]->confidence);
    }

    public function testLazyQuoteContinuationRetainsItsListOwner(): void
    {
        $migration = (new MarkdownToCarve())->convertWithFidelityReport('> - a
lazy
>   more
>
>   [r]: <>
>
> - b

[t][r]');
        $expected = '<blockquote>
  <ul>
    <li><p>a
lazy
more</p></li>
    <li><p>b</p></li>
  </ul>
</blockquote>
<p>t</p>
';
        self::assertSame($expected, (new CarveConverter())->convert($migration->value));
        self::assertSame($expected, (new CarveConverter())->convert(CarveConverter::toCarve($migration->value)));
        $losses = array_filter($migration->diagnostics, static fn ($row) => $row->code === 'structure-unspellable');
        self::assertCount(1, $losses);
        self::assertSame('exact', array_values($losses)[0]->confidence);
    }

    public function testNativeListBoundariesWithLazyLinesEmptyMarkersAndQuoteTabs(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/markdown_definition_list_boundaries.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $migration = (new MarkdownToCarve())->convertWithFidelityReport($case['source']);
            self::assertSame($case['html'], (new CarveConverter())->convert($migration->value));
            self::assertSame($case['html'], (new CarveConverter())->convert(CarveConverter::toCarve($migration->value)));
            $losses = array_filter($migration->diagnostics, static fn ($row) => $row->code === 'structure-unspellable');
            self::assertCount(substr_count($case['nativeHtml'], 'href=""'), $losses);
            foreach ($losses as $loss) {
                self::assertSame('exact', $loss->confidence);
            }
        }
    }

    public function testBlocksBetweenListsDoNotHoldDefinitionsInPlace(): void
    {
        foreach (['# H', '> q', '* * *', "```\nx\n```", "<div>\nx\n</div>"] as $block) {
            $source = "- a\n\n" . $block . "\n\n[r]: /u\n\n- b\n\n[t][r]";
            $written = (new MarkdownToCarve())->convert($source);
            self::assertStringEndsWith('[r]: /u', trim($written));
            self::assertSame(1, substr_count($written, '[r]: /u'));
        }
    }
}
