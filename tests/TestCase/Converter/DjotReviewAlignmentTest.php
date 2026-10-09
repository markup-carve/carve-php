<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotReviewAlignmentTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function emptySpanTails(): array
    {
        return [
            ['', ''],
            ['tail', 'tail'],
            ['[x]', '[x]'],
            ['[x](u)', '<a href="u">x</a>'],
            ['![x](u)', '<img src="u" alt="x">'],
            ['*s*', '<strong>s</strong>'],
            ['_e_', '<em>e</em>'],
            ['`c`', '<code>c</code>'],
            ['{=m=}', '<mark>m</mark>'],
            ['{-d-}', '<del>d</del>'],
            ['^u^', '<sup>u</sup>'],
            ['~d~', '<sub>d</sub>'],
            ['<http://a.b>', '<a href="http://a.b">http://a.b</a>'],
            ['word', 'word'],
            ["'q'", '’q’'],
            ['\\*', '*'],
            ['\\{', '{'],
        ];
    }

    #[DataProvider('emptySpanTails')]
    public function testKeepsEmptyAttributedSpansBeforeInlineConstructs(string $tail, string $html): void
    {
        $converted = (new DjotToCarve())->convert('[x]{}' . $tail . "\n");
        self::assertStringContainsString('[x]{}', $converted);
        self::assertSame('<p><span>x</span>' . $html . '</p>', rtrim((new CarveConverter())->convert($converted)));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function emptySpanContexts(): array
    {
        return [
            ["pre [x]{} post\n", '<p>pre <span>x</span> post</p>'],
            ["*[x]{}*\n", '<p><strong><span>x</span></strong></p>'],
            ["_[x]{}_\n", '<p><em><span>x</span></em></p>'],
            ["[x]{}{}\n", '<p><span>x</span></p>'],
            ["![x]{}\n", '<p>!<span>x</span></p>'],
        ];
    }

    #[DataProvider('emptySpanContexts')]
    public function testKeepsEmptyAttributedSpansInInlineContexts(string $source, string $html): void
    {
        self::assertSame($html, rtrim((new CarveConverter())->convert((new DjotToCarve())->convert($source))));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function emptyAttributesWithoutSpans(): array
    {
        return [
            ["\\[x]{}\n", '<p>[x]</p>'],
            ["[x\\]{}\n", '<p>[x]</p>'],
            ["]{}\n", '<p>]</p>'],
            ["word{}\n", '<p>word</p>'],
            ["[x](u){}\n", '<p><a href="u">x</a></p>'],
            ["*a [b* c]{}\n", '<p><strong>a [b</strong> c]</p>'],
            ["_a [b_ c]{}\n", '<p><em>a [b</em> c]</p>'],
        ];
    }

    #[DataProvider('emptyAttributesWithoutSpans')]
    public function testDropsEmptyAttributesThatDoNotCreateSpans(string $source, string $html): void
    {
        $converted = (new DjotToCarve())->convert($source);
        self::assertStringNotContainsString('{}', $converted);
        self::assertSame($html, rtrim((new CarveConverter())->convert($converted)));
    }

    public function testPreservesEscapedSpacesBeforeHardBreaks(): void
    {
        foreach (['a', '*a*'] as $prefix) {
            foreach (['\\ ', ' \\ ', '\\ \\ '] as $spaces) {
                $source = $prefix . $spaces . "\\\nb\n";
                $converted = (new DjotToCarve())->convert($source);
                self::assertSame($source, $converted);
                $html = $prefix === 'a' ? 'a' : '<strong>a</strong>';
                self::assertSame('<p>' . $html . str_repeat(' ', strlen($spaces) === 2 ? 1 : 2) . "<br>\nb</p>", str_replace('&nbsp;', ' ', rtrim((new CarveConverter())->convert($converted))));
            }
        }
    }

    /**
     * @return list<array{string, string, string}>
     */
    public static function hardBreakWhitespace(): array
    {
        return [
            ["a\\ \\ \n", "a\\ \\\n", "<p>a <br>\n</p>"],
            ["a\\  \t\\\nb\n", "a\\ \\\nb\n", "<p>a <br>\nb</p>"],
            ["a\\\\ \t\\\nb\n", "a\\\\\\\nb\n", "<p>a\\<br>\nb</p>"],
            ["a  \t\\\nb\n", "a\\\nb\n", "<p>a<br>\nb</p>"],
        ];
    }

    #[DataProvider('hardBreakWhitespace')]
    public function testTrimsOnlyUnescapedHardBreakWhitespace(string $source, string $expected, string $html): void
    {
        $converted = (new DjotToCarve())->convert($source);
        self::assertSame($expected, $converted);
        self::assertSame($html, str_replace('&nbsp;', ' ', rtrim((new CarveConverter())->convert($converted))));
    }

    public function testKeepsDashesInEmptyDeletionForms(): void
    {
        foreach (["{--}\n", "x {--} y\n", "{---}\n", "x {---} y\n"] as $source) {
            self::assertSame($source, (new DjotToCarve())->convert($source));
        }
    }

    public function testEscapedDeletionOpenersAndBlankLinesKeepDashesLiteral(): void
    {
        self::assertSame("\\{- a\\-\\-}\n", (new DjotToCarve())->convert("\\{- a--}\n"));
        self::assertSame("> \\{\\-a\n>\n> b\\-\\-}\n", (new DjotToCarve())->convert("> {-a\n>\n> b--}\n"));
    }

    public function testSourceStartNeverUsesLastCharacterAsPrefix(): void
    {
        $converter = new DjotToCarve();
        foreach (['', "\n"] as $ending) {
            self::assertSame('[x][]!' . $ending, $converter->convert('[x][]!' . $ending));
            self::assertSame('`  a  ` 5$' . $ending, $converter->convert('` a ` 5$' . $ending));
            $source = '[[foo](bar)](baz)!' . $ending;
            $result = $converter->convertWithFidelityReport($source);
            self::assertSame($source, $result->value);
            $losses = array_values(array_filter($result->report()['diagnostics'], static fn (array $row): bool => $row['code'] === 'structure-unspellable'));
            self::assertCount(1, $losses);
            self::assertSame('line:1', $losses[0]['path']);
            self::assertSame('exact', $losses[0]['confidence']);
            self::assertIsString($losses[0]['message']);
            self::assertStringContainsString('link', $losses[0]['message']);
        }
    }

    /**
     * @return list<array{string, string}>
     */
    public static function reviewBlockBoundaries(): array
    {
        return [
            ["> a\n# a\n", "> a\n# a\n"],
            ["# a\n  body\n::: x\n", "# a body\n::: x\n:::\n"],
            ["# a\n  body `\n k `\n", "# a body ` k `\n"],
            ["- # h\n  - x\n", "- # h\n  - x\n"],
            ["- a\n\n  # h\n  body\n  - x\n", "- a\n\n  # h body\n  - x\n"],
            ["(1) # h\n    body\n", "1. # h body\n"],
            ["- a\n # h\n", "- a\n \\# h\n"],
            ["1. a\n  # h\n", "1. a\n  \\# h\n"],
        ];
    }

    #[DataProvider('reviewBlockBoundaries')]
    public function testEndsParagraphStateAtBlockBoundaries(string $source, string $expected): void
    {
        self::assertSame($expected, (new DjotToCarve())->convert($source));
    }

    public function testStartsBlocksOutsidePrecedingContainers(): void
    {
        foreach (['> a', '> > a', "> a\n> b", '- a', "- a\n  b", '1. a'] as $container) {
            foreach (['# h', '## h', '***', '> q', "::: x\nin\n:::", '| t |'] as $block) {
                $source = $container . "\n" . $block . "\n";
                self::assertSame($source, (new DjotToCarve())->convert($source));
            }
        }
    }

    public function testEndsHeadingContinuationsBeforeBlocks(): void
    {
        foreach (["# a\n  body", "## a\nbody"] as $heading) {
            foreach (['# h', '## h', '***', '- - -', '> q', '- li', "::: x\nin\n:::", '| t |', 'b. x', 'A) x', 'i. x', 'IV. x'] as $block) {
                if (str_starts_with($block, explode(' ', $heading)[0] . ' ')) {
                    continue;
                }
                self::assertSame(
                    (preg_replace('/\n[ \t]*/', ' ', $heading) ?? $heading) . "\n" . (new DjotToCarve())->convert($block . "\n"),
                    (new DjotToCarve())->convert($heading . "\n" . $block . "\n"),
                );
            }
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function opaquePayloads(): array
    {
        return array_map(static fn (string $source): array => [$source], [
            '<https://example.com/[x][missing]>',
            '[x](a(b[x][missing]c))',
            '![x](a(b[x][missing]c))',
            '`[x][missing]`',
            '`[x][missing]`{=html}',
            '$`[x][missing]`',
            '[t]{k="[x][missing]"}',
            '{% [x][missing] %}',
        ]);
    }

    #[DataProvider('opaquePayloads')]
    public function testOpaquePayloadsHaveNoReferenceLoss(string $source): void
    {
        $this->assertNoLoss($source . "\n");
    }

    /**
     * @return list<array{string, string, string}>
     */
    public static function referenceAttributes(): array
    {
        return [
            ['{.a % .bogus #bogus title=bogus %}', '', '<p><a href="/u" class="a">x</a></p>'],
            ['{.a title="a .bogus #bogus % comment %" key="a b"}', '', '<p><a href="/u" class="a" title="a .bogus #bogus % comment %" key="a b">x</a></p>'],
            ['{.a title=base key="a b"}', '{.b % .bogus key=bogus % title="own .c #d % value %"}', '<p><a href="/u" class="b" title="own .c #d % value %" key="a b">x</a></p>'],
            ['{.a title="a \\"quoted\\" .b #c %"}', '', '<p><a href="/u" class="a" title="a &quot;quoted&quot; .b #c %">x</a></p>'],
            ['{.a class=b .c}', '', '<p><a href="/u" class="b c">x</a></p>'],
        ];
    }

    #[DataProvider('referenceAttributes')]
    public function testReferenceAttributesUseParsedTokens(string $base, string $own, string $expected): void
    {
        $source = '[x][]' . $own . "\n\n" . $base . "\n[x]: /u\n";
        self::assertSame($expected, rtrim((new CarveConverter())->convert((new DjotToCarve())->convert($source))));
    }

    /**
     * @return list<array{string, int}>
     */
    public static function emptyDescriptions(): array
    {
        return [
            ["> : term\n", 1],
            ["- : term\n", 1],
            ["intro\n\n> : term\n", 3],
            ["- first\n- : term\n", 2],
            ["> > : term\n", 1],
            ["> - : term\n", 1],
            ["- > : term\n", 1],
            ["1. : term\n", 1],
            ["> : term\n>\n> outside\n", 1],
            ["- : term\n\n- outside\n", 1],
            ["> : term\n\n  outside\n", 1],
            ["- : term\n- sibling\n\n    body\n", 1],
            ["- : term\n  - sibling\n\n    body\n", 1],
            ["> : term\n> - sibling\n>\n>   body\n", 1],
        ];
    }

    #[DataProvider('emptyDescriptions')]
    public function testContainerDescriptionLossKeepsSourceLine(string $source, int $line): void
    {
        $losses = array_values(array_filter((new DjotToCarve())->convertWithFidelityReport($source)->report()['diagnostics'], static fn (array $row): bool => $row['code'] === 'structure-unspellable'));
        self::assertCount(1, $losses);
        self::assertIsString($losses[0]['message']);
        self::assertStringContainsString('empty definition description', $losses[0]['message']);
        self::assertSame('line:' . $line, $losses[0]['path']);
        self::assertSame('warning', $losses[0]['severity']);
        self::assertSame('dropped', $losses[0]['fidelity']);
        self::assertSame('exact', $losses[0]['confidence']);
    }

    /**
     * @return list<array{string}>
     */
    public static function containerBodies(): array
    {
        return array_map(static fn (string $source): array => [$source], [
            "> : term\n>\n>   body\n",
            "> > : term\n> >\n> >   body\n",
            "> - : term\n>\n>     body\n",
            "- > : term\n  >\n  >   body\n",
            "- : term\n\n    body\n",
            "1. : term\n\n     body\n",
            "- : term\n\n    - nested\n",
            ": term\n - continuation\n\n  body\n",
            "> paragraph\n> : term\n",
            "- paragraph\n  : term\n",
            "> ```\n> : term\n> ```\n",
            "- ```\n  : term\n  ```\n",
            "[^note]: body\n",
            "> [^note]: body\n",
            "- [^note]: body\n",
        ]);
    }

    #[DataProvider('containerBodies')]
    public function testContainerBodiesHaveNoEmptyDescriptionLoss(string $source): void
    {
        $this->assertNoLoss($source);
    }

    public function testCollectsReferencesAfterBlocks(): void
    {
        foreach (['***', '*-*-*', '# h', '> q', '- li', '1. li', "```\nc\n```", '| t |', "::: d\nin\n:::"] as $block) {
            foreach (["[r][]\n\n" . $block . "\n[r]: /u\n", $block . "\n[r]: /u\n\n[r][]\n"] as $source) {
                $this->assertNoLoss($source);
            }
        }
        self::assertContains('structure-unspellable', array_column((new DjotToCarve())->convertWithFidelityReport("[r][]\n\npara\n[r]: /u\n")->report()['diagnostics'], 'code'));
    }

    public function testRegistersCompleteHeadingLabels(): void
    {
        foreach (["# a\ncontinued", "## a\n## continued", "#\na\ncontinued", "# a\n  continued"] as $heading) {
            $this->assertNoLoss("[a continued][]\n\n" . $heading . "\n");
            self::assertContains('structure-unspellable', array_column((new DjotToCarve())->convertWithFidelityReport("[a][]\n\n" . $heading . "\n")->report()['diagnostics'], 'code'));
        }
        self::assertContains('structure-unspellable', array_column((new DjotToCarve())->convertWithFidelityReport("[continued][]\n\n# a\n# continued\n")->report()['diagnostics'], 'code'));
        $this->assertNoLoss("[a][]\n\n# a\n- continued\n");
        self::assertContains('structure-unspellable', array_column((new DjotToCarve())->convertWithFidelityReport("[a continued][]\n\n# a\n- continued\n")->report()['diagnostics'], 'code'));
    }

    public function testClosesDivsInTheirOwningItems(): void
    {
        foreach (['-', '*', '1.'] as $marker) {
            $indent = $marker === '1.' ? '   ' : '  ';
            $next = $marker === '1.' ? '2.' : $marker;
            foreach (['', "\n", "\n\n"] as $gap) {
                $source = $marker . " ::: foo\n" . $indent . "x\n" . $gap . $next . " y\n";
                $converted = (new DjotToCarve())->convert($source);
                self::assertStringContainsString($indent . "x\n" . $indent . ":::\n" . $gap . $next . ' y', $converted);
                self::assertMatchesRegularExpression('/<\/div>\s*<\/li>\s*<li>(?:<p>)?y/', (new CarveConverter())->convert($converted));
            }
        }
        self::assertStringContainsString("  x\n  :::\n- y\n", (new DjotToCarve())->convert("- a\n\n  ::: foo\n  x\n- y\n"));
    }

    public function testKeepsCodePaddingOutOfDestinationsAndOpaqueSpans(): void
    {
        foreach (['[x]', '![x]'] as $label) {
            self::assertSame($label . "(a%60%20b%20%60c)\n", (new DjotToCarve())->convert($label . "(a` b `c)\n"));
            self::assertSame($label . "(a%60%20b%20%60c) and `  code  `\n", (new DjotToCarve())->convert($label . "(a` b `c) and ` code `\n"));
            self::assertSame($label . "(a%28b{}c%29)\n", (new DjotToCarve())->convert($label . "(a(b{}c))\n"));
        }
        foreach (["`` x ``{=html}\n", "$`` x ``\n"] as $source) {
            self::assertSame($source, (new DjotToCarve())->convert($source));
        }
    }

    public function testWordAttributesIncludeEscapedCharacters(): void
    {
        $carve = (new DjotToCarve())->convert("foo\\*bar{.c}\n");
        self::assertSame("[foo\\*bar]{.c}\n", $carve);
        self::assertSame("<p><span class=\"c\">foo*bar</span></p>\n", (new CarveConverter())->convert($carve));
    }

    public function testNativeAttributeValuesAndComments(): void
    {
        self::assertSame("[t]{k=\"\\` x \\`\"}\n", (new DjotToCarve())->convert("[t]{k=\"` x `\"}\n"));
        self::assertSame("%%\n", (new DjotToCarve())->convert("{% ` x ` %}\n"));
    }

    public function testContinuedOrderedMarkersAndLooseLists(): void
    {
        foreach (['# h', '> q', '| t |', "::: n\n   in\n   :::", '***'] as $block) {
            self::assertStringContainsString('   1\\. c', (new DjotToCarve())->convert("1. a\n   " . $block . "\n   1. c\n2. d\n"));
        }
        foreach (['-', '*'] as $marker) {
            $source = $marker . " a\n  " . $marker . " c\n\n  # h\n" . $marker . " d\n";
            self::assertStringContainsString('<li><p>d</p></li>', (new CarveConverter())->convert((new DjotToCarve())->convert($source)));
        }
    }

    public function testReferenceAttributesStayOpaqueAndApplyToNestedUses(): void
    {
        foreach (
            [
                ["{title=foo}\n[x]: /u\n\n[hi]{title=\"[x][]\"}\n", '<p><span title="[x][]">hi</span></p>'],
                ["[*a*][]\n\n[![i](x)][a]\n\n[a]: /u\n", "<p><a href=\"/u\"><strong>a</strong></a></p>\n<p><a href=\"/u\"><img src=\"x\" alt=\"i\"></a></p>"],
                ["{title=t}\n[ref]: /u\n\n[![i](x)][ref]\n", '<p><a href="/u" title="t"><img src="x" alt="i"></a></p>'],
            ] as [$source, $expected]
        ) {
            self::assertSame($expected, rtrim((new CarveConverter())->convert((new DjotToCarve())->convert($source))));
        }
    }

    private function assertNoLoss(string $source): void
    {
        self::assertNotContains('structure-unspellable', array_column((new DjotToCarve())->convertWithFidelityReport($source)->report()['diagnostics'], 'code'), $source);
    }
}
