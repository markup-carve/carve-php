<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\QuoteFenceLinter;
use PHPUnit\Framework\TestCase;

/**
 * The one authoring hazard around `::: >` that no other diagnostic reaches
 * (markup-carve/carve#1718). Rule id and message mirror carve-js `lint.ts`,
 * the parity reference.
 */
class QuoteFenceLinterTest extends TestCase
{
    protected QuoteFenceLinter $linter;

    protected function setUp(): void
    {
        $this->linter = new QuoteFenceLinter();
    }

    /**
     * @param string $source
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    protected function reports(string $source): array
    {
        return array_values(array_filter(
            $this->linter->lint($source),
            static fn ($warning): bool => $warning->rule === QuoteFenceLinter::RULE_QUOTE_FENCE_ENDS_THE_QUOTE_ABOVE,
        ));
    }

    public function testReportsTheOpenerAndTheRenderShowsWhy(): void
    {
        $source = "> a\n::: >\nb\n:::\n";
        $this->assertSame(
            "<blockquote><p>a</p></blockquote>\n<blockquote><p>b</p></blockquote>\n",
            (new CarveConverter())->convert($source),
        );

        $warnings = $this->reports($source);
        $this->assertCount(1, $warnings);
        $this->assertSame(2, $warnings[0]->line);
        $this->assertSame(1, $warnings[0]->column);
        $this->assertStringContainsString('opens a sibling one', $warnings[0]->message);
        $this->assertStringContainsString('"> ::: >"', $warnings[0]->message);
    }

    public function testReportsItWhereverTheContentColumnSits(): void
    {
        // Container, list item and footnote body: the same mistake at three
        // columns, which is why the pass reads siblings rather than lines.
        $this->assertCount(1, $this->reports(":::: note\n> a\n::: >\nb\n:::\n::::\n"));
        $this->assertCount(1, $this->reports("- > a\n  ::: >\n  b\n  :::\n"));
        $this->assertCount(1, $this->reports("[^a]: > q\n      ::: >\n      b\n      :::\n\nsee[^a]\n"));
    }

    public function testSaysNothingAboutTheNestedSpellingWhichNeedsTheMarker(): void
    {
        $source = "> ::: >\n> b\n> :::\n";
        $this->assertSame(
            "<blockquote>\n  <blockquote><p>b</p></blockquote>\n</blockquote>\n",
            (new CarveConverter())->convert($source),
        );
        $this->assertSame([], $this->reports($source));
    }

    public function testSaysNothingWhenTheBlankLineMakesTwoQuotesDeliberate(): void
    {
        $this->assertSame([], $this->reports("> a\n\n::: >\nb\n:::\n"));
    }

    public function testSaysNothingAboutAFencedQuoteBelowAFencedQuote(): void
    {
        // Both spellings are one node, but only the prefixed one leaves the
        // author no visible cue: after a closing fence the sibling is where it
        // looks.
        $this->assertSame([], $this->reports("::: >\na\n:::\n::: >\nb\n:::\n"));
    }

    public function testSaysNothingAboutALoneQuoteInEitherSpelling(): void
    {
        $this->assertSame([], $this->reports("> a\n> b\n"));
        $this->assertSame([], $this->reports("::: >\nb\n:::\n"));
    }
}
