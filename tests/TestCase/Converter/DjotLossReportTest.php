<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotLossReportTest extends TestCase
{
    /**
     * @return array<string, array{string, int, string}>
     */
    public static function losses(): array
    {
        return [
            'nested forced emphasis' => ["_({_foo_})_\n", 1, 'Nested emphasis'],
            'nested strong' => ["*****a*****\n", 1, 'Nested emphasis'],
            'nested bare emphasis' => ["__emphasis inside_ emphasis_\n", 1, 'Nested emphasis'],
            'empty heading' => ["##\n", 1, 'empty heading'],
            'empty destination' => ["[link][]\n\n[link]:\n[link2]: url\n", 1, 'empty destination'],
            'unresolved multiline label' => ["[link][a and\nb]\n", 1, 'unresolved Djot reference'],
            'multiline definition is text' => ["[link][a and\nb]\n\n[a and\nb]: url\n", 1, 'unresolved Djot reference'],
            'case-sensitive reference' => ["[Link][]\n\n[link]: /url\n", 1, 'unresolved Djot reference'],
            'definition inside fenced code' => ["[x][r]\n\n```\n\n[r]: /url\n```\n", 1, 'unresolved Djot reference'],
            'nested link' => ["[[foo](bar)](baz)\n", 1, 'link inside a link'],
            'empty definition description' => [": apple\n fruit\n\n  Body\n\n: orange\n", 6, 'empty definition description'],
            'interior table separator' => ["|a|b|\n|:-|---:|\n|c|d|\n|cc|dd|\n|-:|:-:|\n|e|f|\n", 5, 'separator inside a table'],
            'separator-only table' => ["|--|--|\n", 1, 'only separator rows'],
            'blank table' => ["| |\n", 1, 'only row has blank cells'],
            'source line before folds' => ["##\nheading\n\n{title=foo}\n[ref]: /url\n\n[missing][]\n", 7, 'unresolved Djot reference'],
            'source line after frontmatter' => ["---\ntitle: Demo\n---\n\n##\n", 5, 'empty heading'],
            'CRLF line numbers' => ["paragraph\r\n\r\n##\r\n", 3, 'empty heading'],
        ];
    }

    #[DataProvider('losses')]
    public function testNamesTheLossAtItsSourceLine(string $source, int $line, string $message): void
    {
        $result = (new DjotToCarve())->convertWithFidelityReport($source);
        $diagnostics = $result->report()['diagnostics'];
        self::assertContains('fidelity-unverified', array_column($diagnostics, 'code'));
        $losses = array_values(array_filter($diagnostics, static fn (array $row): bool => $row['code'] === 'structure-unspellable' && is_string($row['message']) && str_contains($row['message'], $message)));
        self::assertNotEmpty($losses);
        foreach ($losses as $loss) {
            self::assertSame('warning', $loss['severity']);
            self::assertSame('dropped', $loss['fidelity']);
            self::assertSame('exact', $loss['confidence']);
            self::assertSame('line:' . $line, $loss['path']);
        }
    }

    public function testCodeAndResolvedReferencesHaveNoStructuralLoss(): void
    {
        foreach (["```\n##\n[x][]\n|--|--|\n```\n", '`_({_foo_})_`', "[x][r]\n\n[r]: /url\n", "- a\n\n  - b\n  - c\n\n- d\n", "[x][]\n\n[x]:\n url\n", "See [Introduction][].\n\n# Introduction\n", "[link _and_ link][]\n\n[link and link]: url\n", "[![image](img)](url)\n", "\n|`|\n", '| `a |`', '[literal]()'] as $source) {
            self::assertNotContains('structure-unspellable', array_column((new DjotToCarve())->convertWithFidelityReport($source)->report()['diagnostics'], 'code'));
        }
    }

    public function testReferenceAttributesMergeWithTheLinksOwnKeys(): void
    {
        $source = "{title=foo .old}\n[ref]: /url\n\n[ref][]{title=bar .new}\n";
        $value = (new DjotToCarve())->convert($source);
        self::assertSame("[ref](/url){title=\"bar\" class=\"new\"}\n", $value);
        self::assertStringNotContainsString('title=foo', $value);
        self::assertStringNotContainsString('}{', $value);
    }

    public function testCodePaddingKeepsBacktickEdgePadding(): void
    {
        foreach (["` ``a`` `\n", "`` `foo` ``\n"] as $source) {
            self::assertSame($source, (new DjotToCarve())->convert($source));
        }
        self::assertSame("`  value  `\n", (new DjotToCarve())->convert("` value `\n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function preservedSpellings(): array
    {
        return [
            'hard break with trailing whitespace' => ["ab\\\t  \nc\n"],
            'unresolved wrapped reference' => ["[link][a and\nb]\n"],
            'wrapped definition remains text' => ["[link][a and\nb]\n\n[a and\nb]: url\n"],
            'unterminated code remains open' => ["\n|`|x\n"],
            'pipe inside closed code' => ["| `a |`\n"],
            'nested list without trailing blank' => ["- a\n\n  - b\n  - c\n- d\n"],
            'loose parent item with nested list' => ["- one\n  and\n\n  another paragraph\n\n  - a list\n\n- two\n"],
        ];
    }

    #[DataProvider('preservedSpellings')]
    public function testPreservesUnaffectedSpellings(string $source): void
    {
        self::assertSame($source, (new DjotToCarve())->convert($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function listBlockSpellings(): array
    {
        return [
            'loose item before nested list' => ["- a\n\n  para text\n\n  - c\n\n- d\n"],
            'heading before nested list' => ["- a\n\n  # h\n\n  - c\n\n- d\n"],
            'code before nested list' => ["- a\n\n  ```\n  x\n  ```\n\n  - c\n\n- d\n"],
            'quote before nested list' => ["- a\n\n  > q\n\n  - c\n\n- d\n"],
            'table before nested list' => ["- a\n\n  | t |\n\n  - c\n\n- d\n"],
            'div before nested list' => ["- a\n\n  ::: n\n  in\n  :::\n\n  - c\n\n- d\n"],
            'block after nested list' => ["- a\n\n  - c\n\n  ***\n- d\n"],
        ];
    }

    #[DataProvider('listBlockSpellings')]
    public function testPreservesListBlockBoundaries(string $source): void
    {
        self::assertSame($source, (new DjotToCarve())->convert($source));
    }

    public function testEscapesBlockMarkersInListParagraphContinuations(): void
    {
        foreach (['# h', '> q', '| t |'] as $block) {
            $source = "- a\n  " . $block . "\n  - c\n- d\n";
            self::assertSame("- a\n  \\" . $block . "\n  \\- c\n- d\n", (new DjotToCarve())->convert($source));
        }
        self::assertSame("1. a\n   1\\. c\n2. d\n", (new DjotToCarve())->convert("1. a\n   1. c\n2. d\n"));
    }

    public function testEmptyAttributesPreserveAdjacentStrongSpans(): void
    {
        foreach (['*a*{}*b*', '*a*{}{}*b*'] as $source) {
            $carve = (new DjotToCarve())->convert($source);
            self::assertSame('{*a*}{%%}{*b*}', $carve);
            self::assertSame("<p><strong>a</strong><strong>b</strong></p>\n", (new CarveConverter())->convert($carve));
        }
    }

    public function testKeepsDefinitionUsedByAnOrdinaryReference(): void
    {
        $source = "[link _and_ link][] and [plain][link and link]\n\n[link and link]: url\n";
        self::assertSame("[link /and/ link](url) and [plain][link and link]\n\n[link and link]: url\n", (new DjotToCarve())->convert($source));
    }

    public function testRemovesInlinedDefinitionAfterFoldingAWrappedLabel(): void
    {
        $source = "[wrapped][a and\nb] and [ref][]\n\n[a and b]: url\n\n{title=\"two words\"}\n[ref]: /url\n";
        self::assertSame("[wrapped][a and b] and [ref](/url){title=\"two words\"}\n\n[a and b]: url\n", (new DjotToCarve())->convert($source));
    }

    public function testDefinitionTermContinuationIsDedented(): void
    {
        self::assertStringContainsString(":: apple\nfruit\n", (new DjotToCarve())->convert(": apple\n fruit\n\n  Body\n\n: orange\n"));
    }

    public function testRemovingAnInlinedDefinitionPreservesParagraphSeparation(): void
    {
        $source = "[b][]\n\n{title=t}\n[b]: /u\n\nnext\n";
        $carve = (new DjotToCarve())->convert($source);
        self::assertSame("[b](/u){title=\"t\"}\n\nnext\n", $carve);
        self::assertSame("<p><a href=\"/u\" title=\"t\">b</a></p>\n<p>next</p>\n", (new CarveConverter())->convert($carve));
    }
}
