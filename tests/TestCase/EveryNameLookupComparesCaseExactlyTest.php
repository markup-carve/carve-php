<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\HeadingNumbersExtension;
use MarkupCarve\Carve\Extension\LowercaseHeadingIdsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9R R1, CARVE-P9R-010: every name lookup compares case exactly. Ids keep
 * their case; only the lookup changed.
 */
class EveryNameLookupComparesCaseExactlyTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function caseOnlyMisses(): array
    {
        return [
            'a heading crossref' => ["# Getting Started\n\nSee </#getting-started>.\n", 'See &lt;/#getting-started&gt;.'],
            'a figure caption crossref' => [
                "{#Fig-A}\n![x](a.jpg)\n^ Figure #: A\n\nSee </#fig-a>.\n",
                'See &lt;/#fig-a&gt;.',
            ],
            'an equation crossref' => [
                "{#Eq-E}\n" . '$$`E = mc^2`' . "\n^ Equation #: mass-energy\n\nSee </#eq-e>.\n",
                'See &lt;/#eq-e&gt;.',
            ],
            'a collapsed heading reference' => ["See [plan][].\n\n# Plan\n", 'See [plan][].'],
            'an explicit definition label' => ["[y][Label]\n\n[label]: /u\n", '[y][Label]'],
            'a quoted heading crossref' => ["> # Inner\n\nSee </#inner>.\n", 'See &lt;/#inner&gt;.'],
            'a crossref in a footnote body' => ["# Plan\n\nUse[^n].\n\n[^n]: See </#plan>.\n", 'See &lt;/#plan&gt;.'],
            'a collapsed reference in a footnote body' => ["# Plan\n\nUse[^n].\n\n[^n]: See [plan][].\n", 'See [plan][].'],
            'a sharp s, no full case fold' => ["# Straße\n\nSee </#straße>.\n", 'See &lt;/#straße&gt;.'],
            'a dotted capital I' => ["# İstanbul\n\nSee </#i\u{0307}stanbul>.\n", "See &lt;/#i\u{0307}stanbul&gt;."],
            'a final sigma crossref' => ["# ΣΟΦΟΣ\n\nSee </#σοφοσ>.\n", 'See &lt;/#σοφοσ&gt;.'],
            'a final sigma collapsed reference' => ["# ΣΟΦΟΣ\n\nSee [σοφοσ][].\n", 'See [σοφοσ][].'],
        ];
    }

    #[DataProvider('caseOnlyMisses')]
    public function testACaseOnlyMismatchStaysLiteral(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);

        $this->assertStringContainsString($expected, $html);
        $this->assertDoesNotMatchRegularExpression('/<a href="#(?!fn)/', $html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function exactHits(): array
    {
        return [
            'a heading crossref' => ["# Getting Started\n\nSee </#Getting-Started>.\n", 'See <a href="#Getting-Started">Getting Started</a>.'],
            'a figure caption crossref' => [
                "{#Fig-A}\n![x](a.jpg)\n^ Figure #: A\n\nSee </#Fig-A>.\n",
                'See <a href="#Fig-A">Figure 1</a>.',
            ],
            'an equation crossref' => [
                "{#Eq-E}\n" . '$$`E = mc^2`' . "\n^ Equation #: mass-energy\n\nSee </#Eq-E>.\n",
                'See <a href="#Eq-E">Equation 1</a>.',
            ],
            'a collapsed heading reference, whitespace collapsed' => [
                "See [Getting   Started][].\n\n# Getting Started\n",
                '<a href="#Getting-Started">Getting   Started</a>',
            ],
            'ids differing only in case are two targets' => [
                "{#Tip}\n# Upper\n\n{#tip}\n# Lower\n\n</#tip> and </#Tip>\n",
                '<a href="#tip">Lower</a> and <a href="#Tip">Upper</a>',
            ],
        ];
    }

    public function testANumberedHeadingCrossrefComparesCaseExactly(): void
    {
        $converter = (new CarveConverter())->addExtension(new HeadingNumbersExtension());
        $html = $converter->convert("# Setup\n\nSee </#setup> and </#Setup>.\n");

        $this->assertStringContainsString('See &lt;/#setup&gt; and <a href="#Setup">', $html);
    }

    public function testALowercasedIdIsReachedOnlyByItsOwnSpelling(): void
    {
        $converter = (new CarveConverter())->addExtension(new LowercaseHeadingIdsExtension());
        $html = $converter->convert("# Plan\n\nSee </#Plan> and </#plan>.\n");

        $this->assertStringContainsString('See &lt;/#Plan&gt; and <a href="#plan">Plan</a>.', $html);
    }

    public function testAMarkdownFragmentLinkKeepsOnlyItsExactHeadingAnchor(): void
    {
        $markdown = CarveConverter::markdown()->convert("{#Plan}\n# Plan\n\n[x](#plan)\n");

        $this->assertStringNotContainsString('{#Plan}', $markdown);
    }

    #[DataProvider('exactHits')]
    public function testAnExactSpellingResolves(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }
}
