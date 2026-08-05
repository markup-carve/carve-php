<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A reference label matches EXACTLY. §6 and PART 9R R1 both say it in the same
 * words: "case-sensitive, no whitespace folding".
 *
 * This engine folded whitespace in three places - the definition key, the explicit
 * `[text][ref]` lookup, and the collapsed `[text][]` form that derives its label
 * from the link text - so a label whose spacing differed from the definition's
 * still resolved. carve-js had the same defect in its explicit form and fixed it
 * in carve-js#674; carve-rs was already exact on both.
 *
 * EXACT means exact, not stripped: identical padding and doubled internal spaces
 * still match, so trimming would be just as wrong as collapsing.
 *
 * The IMPLICIT heading-reference path is deliberately fuzzier and is not touched -
 * `HeadingReferenceCollector` folds whitespace and case when matching
 * `[Some Heading][]` against a heading's text, which all four implementations do.
 *
 * Nothing in the corpus uses a label containing whitespace, which is how half of a
 * two-clause rule drifted in two engines while the corpus stayed green.
 */
class ReferenceLabelsMatchExactlyTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    protected function resolves(string $source): bool
    {
        return str_contains($this->html($source), 'href="/u"');
    }

    public function testDifferingWhitespaceInTheReferenceDoesNotResolve(): void
    {
        $this->assertFalse($this->resolves("see [t][ b  c]\n\n[b c]: /u\n"));
    }

    public function testDifferingWhitespaceInTheDefinitionDoesNotResolve(): void
    {
        $this->assertFalse($this->resolves("see [t][b c]\n\n[ b  c]: /u\n"));
    }

    public function testIdenticalPaddingDoesResolve(): void
    {
        // Exact, not stripped - trimming would break this.
        $this->assertTrue($this->resolves("see [t][b ]\n\n[b ]: /u\n"));
        $this->assertTrue($this->resolves("see [t][ b]\n\n[ b]: /u\n"));
    }

    public function testIdenticalDoubledInternalSpacingDoesResolve(): void
    {
        // Collapsing would break this.
        $this->assertTrue($this->resolves("see [t][b  c]\n\n[b  c]: /u\n"));
    }

    public function testTheCollapsedFormIsExactToo(): void
    {
        // `[text][]` takes the link text as its label, and that is not folded
        // either. carve-js and carve-rs agree on both rows; the executable spec
        // folds here, which is its own defect (reported separately).
        $this->assertFalse($this->resolves("see [ b  c][]\n\n[b c]: /u\n"));
        $this->assertTrue($this->resolves("see [ b  c][]\n\n[ b  c]: /u\n"));
    }

    public function testTheOrdinaryCaseStillResolves(): void
    {
        $this->assertTrue($this->resolves("see [t][b c]\n\n[b c]: /u\n"));
        $this->assertTrue($this->resolves("see [b c][]\n\n[b c]: /u\n"));
    }

    public function testCaseIsStillNotNormalized(): void
    {
        // The half that was always right.
        $this->assertFalse($this->resolves("see [t][BAR]\n\n[bar]: /u\n"));
    }

    public function testAnImplicitHeadingReferenceStillFoldsWhitespaceAndCase(): void
    {
        // The fuzzy path, deliberately unchanged: all four implementations match
        // `[my heading][]` against `# My  Heading`.
        $html = $this->html("# My  Heading\n\nsee [my heading][]\n");

        $this->assertStringContainsString('href="#My-Heading"', $html);
    }
}
