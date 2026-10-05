<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Extension\LowercaseHeadingIdsExtension;
use MarkupCarve\Carve\Lint\ReferenceLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReferenceLinterTest extends TestCase
{
    public function testReportsUnresolvedReferencesAndDuplicateDefinitions(): void
    {
        $cases = [
            'duplicate-heading-id' => "# A\n\n# A\n",
            'broken-crossref' => "see </#nope>\n",
            'unresolved-reference-link' => "see [text][nope]\n",
            'unresolved-footnote' => "see[^nope]\n",
            'unused-footnote-definition' => "text\n\n[^a]: never used\n",
            'duplicate-footnote-definition' => "x[^a]\n\n[^a]: one\n\n[^a]: two\n",
            'footnote-labels-differ-only-in-whitespace' => "see [^a b]\n\n[^a b]: one\n\n[^a  b]: two\n",
        ];
        foreach ($cases as $rule => $source) {
            $warnings = (new ReferenceLinter())->lint($source);
            $this->assertContains($rule, array_column($warnings, 'rule'), $source);
        }
    }

    public function testResolvedReferencesDoNotProduceWarnings(): void
    {
        $source = "# Target\n\nsee </#Target> [Target][] [text][r] and [^a b]\n\n[r]: u\n\n[^a  b]: note\n";
        $this->assertSame([], (new ReferenceLinter())->lint($source));
    }

    public function testLiteralExamplesDoNotCountAsDuplicateDefinitions(): void
    {
        foreach (["```\n[^a]: sample\n```", "%%%\n[^a]: sample\n%%%", '\\[^a]: sample', '`[^a]: sample`'] as $body) {
            $source = "x[^a]\n\n[^a]: note\n\n{$body}\n";
            $this->assertNotContains('duplicate-footnote-definition', array_column((new ReferenceLinter())->lint($source), 'rule'));
        }
    }

    public function testUnicodeAndCrLfLocationsUseOriginalBytes(): void
    {
        $source = "😀\r\n\r\n> see </#missing>\r\n";
        $warnings = (new ReferenceLinter())->lint($source);
        $this->assertCount(1, $warnings);
        $this->assertSame(3, $warnings[0]->line);
        $this->assertSame('</#missing>', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }

    public function testRepeatedDefinitionsInQuotesKeepTheirLocation(): void
    {
        $source = "x[^a]\r\n\r\n> [^a]: one\r\n\r\n> [^a]: two\r\n";
        $warnings = (new ReferenceLinter())->lint($source);
        $this->assertCount(1, $warnings);
        $this->assertSame('duplicate-footnote-definition', $warnings[0]->rule);
        $this->assertSame(5, $warnings[0]->line);
        $this->assertSame(3, $warnings[0]->column);
        $this->assertSame('[^a]:', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }

    public function testHeadingCollisionsFollowAssignedIdsAndDocumentOrder(): void
    {
        foreach (
            [
                ["# A\n\n{#A}\n# X\n", 1],
                ["x[^n]\n\n[^n]: text\n\n  # A\n\n# A\n", 5],
                ["{#same}\n# First\n\n{#same}\n# Second\n", 5],
            ] as [$source, $line]
        ) {
            $warnings = array_values(array_filter((new ReferenceLinter())->lint($source), static fn ($w): bool => $w->rule === 'duplicate-heading-id'));
            $this->assertCount(1, $warnings);
            $this->assertSame($line, $warnings[0]->line);
        }
    }

    public function testNestedListDefinitionsAreChecked(): void
    {
        $source = "x[^a]\n\n- [^a]: one\n\n- - [^a]: two\n";
        $warnings = (new ReferenceLinter())->lint($source);
        $this->assertCount(1, $warnings);
        $this->assertSame('duplicate-footnote-definition', $warnings[0]->rule);
        $this->assertSame(5, $warnings[0]->line);
        $this->assertSame(5, $warnings[0]->column);
    }

    public function testUnusedDefinitionsSelectTheMarkerAndWhitespaceWarningsNameBothLabels(): void
    {
        $source = "[^a]: body\n";
        $warning = (new ReferenceLinter())->lint($source)[0];
        $this->assertSame('[^a]:', substr($source, $warning->start, $warning->end - $warning->start));
        $warnings = (new ReferenceLinter())->lint("see[^a b]\n\n[^a b]: first\n\n[^a  b]: second\n");
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('[^a b]', $warnings[0]->message);
        $this->assertStringContainsString('[^a  b]', $warnings[0]->message);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function crossrefOnOtherElements(): array
    {
        return [
            'a paragraph' => ["{#para}\nA para.\n\nSee </#para>.", 'paragraph', 'para'],
            'an uncaptioned table' => ["{#tbl}\n| A |\n|---|\n| 1 |\n\nSee </#tbl>.", 'table', 'tbl'],
            'an inline span, in another case' => ["[x]{#Spot}\n\nSee </#spot>.", 'span', 'Spot'],
        ];
    }

    #[DataProvider('crossrefOnOtherElements')]
    public function testCrossrefNamesTheElementCarryingTheId(string $source, string $kind, string $id): void
    {
        $warnings = (new ReferenceLinter())->lint($source);
        $this->assertSame(['broken-crossref'], array_column($warnings, 'rule'));
        $this->assertStringContainsString('which is on a ' . $kind, $warnings[0]->message);
        $this->assertStringContainsString('[text](#' . $id . ')', $warnings[0]->message);
    }

    public function testCrossrefKeepsTheGenericMessageWhenNoElementCarriesTheId(): void
    {
        $this->assertStringContainsString('has no matching heading id', (new ReferenceLinter())->lint('See </#nope>.')[0]->message);
    }

    public function testFragmentLinkWithNoMatchingIdIsReportedAtTheLink(): void
    {
        $source = "# Intro\n\nSee [bad](#nope).";
        $warnings = (new ReferenceLinter())->lint($source);
        $this->assertSame(['broken-fragment-link'], array_column($warnings, 'rule'));
        $this->assertSame('Link to "#nope" matches no id in this document, so the link goes nowhere.', $warnings[0]->message);
        $this->assertSame([3, 5], [$warnings[0]->line, $warnings[0]->column]);
        $this->assertSame('[bad](#nope)', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenFragmentLinkContainers(): array
    {
        return [
            'a blockquote' => ['> [x](#nope)'],
            'a list item' => ['- [x](#nope)'],
            'a footnote body' => ["Text[^n].\n\n[^n]: [x](#nope)"],
            'a reference definition' => ["[x][r]\n\n[r]: #nope"],
            'an id spelled in an attribute value of raw HTML' => ["``` =html\n<div title=\" id=phantom\"></div>\n```\n\n[x](#phantom)"],
            'an id spelled in an HTML comment' => ["``` =html\n<!-- <div id=\"phantom\"></div> -->\n```\n\n[x](#phantom)"],
            'an id inside template contents' => ["``` =html\n<template><div id=\"hidden\"></div></template>\n```\n\n[x](#hidden)"],
            'an id quoted in a code block' => ["``` html\n<div id=\"raw\"></div>\n```\n\n[x](#raw)"],
            'raw HTML ids decoded the way a browser reads them' => ["``` =html\n<div title=\">\" id=\"r&amp;d\"></div><p id=\"&#1114112;\"></p>\n```\n\n[x](#r&d) [y](#nope)"],
            'deeply nested raw HTML' => ["``` =html\n" . str_repeat('<div>', 10000) . "<p id=\"deep\"></p>\n```\n\n[x](#deep) [y](#nope)"],
        ];
    }

    #[DataProvider('brokenFragmentLinkContainers')]
    public function testBrokenFragmentLinkIsReportedOnce(string $source): void
    {
        $this->assertSame(['broken-fragment-link'], array_column((new ReferenceLinter())->lint($source), 'rule'));
    }

    public function testFragmentLinkDifferingOnlyInCaseNamesTheRealId(): void
    {
        $warnings = (new ReferenceLinter())->lint("# Getting Started\n\n[x](#getting-started)");
        $this->assertSame(['broken-fragment-link'], array_column($warnings, 'rule'));
        $this->assertSame(
            'Link to "#getting-started" matches no id; the id "Getting-Started" differs only in case, and fragment links are case-sensitive, so the link goes nowhere.',
            $warnings[0]->message,
        );
    }

    /**
     * @return array<string, array{0: string, 1?: array{extensions?: list<string|\MarkupCarve\Carve\Extension\ExtensionInterface>}}>
     */
    public static function resolvedFragmentLinks(): array
    {
        return [
            'an auto heading id' => ["# Getting Started\n\n[x](#Getting-Started)"],
            'a lowercased heading id' => ["# Getting Started\n\n[x](#getting-started)", ['extensions' => [new LowercaseHeadingIdsExtension()]]],
            'an implicit heading link under lowercased ids' => ["# Getting Started\n\n[Getting Started][]", ['extensions' => [new LowercaseHeadingIdsExtension()]]],
            'a suffixed duplicate heading id' => ["# A\n\n# A\n\n[x](#A-2)"],
            'a percent-encoded heading id' => ["# Über uns\n\n[x](#%C3%9Cber-uns) [y](#Über-uns)"],
            'an explicit block id' => ["{#tbl}\n| A |\n|---|\n| 1 |\n\n[x](#tbl)"],
            'an inline span id' => ["[x]{#sp}\n\n[y](#sp)"],
            'a footnote id' => ["Text[^n].\n\n[^n]: Back to [ref](#fnref1).\n\n[x](#fn1)"],
            'an id inside raw HTML' => ["``` =html\n<div id=\"raw\"></div>\n```\n\n[x](#raw)"],
            'an anchor name inside raw HTML' => ["``` =html\n<a name=\"old\"></a>\n```\n\n[x](#old)"],
            'the top of the page' => ['[x](#top) [y](#)'],
            'a text fragment' => ['[x](#:~:text=word)'],
            'an id followed by a text directive' => ["# Intro\n\n[x](#Intro:~:text=word)"],
            'a link into another file' => ['[x](other.crv#nope) [y](https://example.com/#nope)'],
            'a citation id when citations render' => ['[x](#ref-smith) [y](#cite-smith)', ['extensions' => ['citations']]],
            'any id when an extension may generate it' => ['[x](#tab-1)', ['extensions' => ['tabs']]],
        ];
    }

    /**
     * @param string $source
     * @param array{extensions?: list<string|\MarkupCarve\Carve\Extension\ExtensionInterface>} $options
     */
    #[DataProvider('resolvedFragmentLinks')]
    public function testFragmentLinkIsNotReported(string $source, array $options = []): void
    {
        $this->assertNotContains('broken-fragment-link', array_column((new ReferenceLinter())->lint($source, $options), 'rule'));
    }

    public function testCitationsStillReportOtherFragments(): void
    {
        $warnings = (new ReferenceLinter())->lint('[x](#ref-smith) [y](#nope)', ['extensions' => ['citations']]);
        $this->assertSame(['broken-fragment-link'], array_column($warnings, 'rule'));
    }
}
