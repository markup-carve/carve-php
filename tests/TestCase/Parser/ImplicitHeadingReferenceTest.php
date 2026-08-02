<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 R1: which headings a `[Heading][]` reference can reach.
 *
 * The index used to come from a line-based pre-scan matching `^#{1,6}` at
 * column 0, so what it found came down to source indentation: a div's inner
 * lines start at column 0 and were indexed, a list item's are indented and were
 * not, and a blockquote's carry `>` and were not. Two of those three answers
 * were right and all three were accidents - this engine had never implemented
 * the blockquote rule, it just never saw past the prefix (carve-php#572).
 *
 * It is now built from the parsed tree, which asks the question the rule
 * actually asks: does this heading have a blockquote ANCESTOR. These cases pin
 * the whole matrix, because the two right answers were previously right for the
 * wrong reason and would have drifted the first time a container changed shape.
 */
class ImplicitHeadingReferenceTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    protected function assertResolves(string $source): void
    {
        $this->assertStringContainsString('href="#H"', $this->html($source));
    }

    protected function assertLiteral(string $source): void
    {
        $this->assertStringNotContainsString('href="#H"', $this->html($source));
    }

    public function testResolvesAtTopLevel(): void
    {
        $this->assertResolves("# H\n\nSee [H][].\n");
    }

    public function testResolvesInsideADiv(): void
    {
        $this->assertResolves(":::\n# H\n:::\n\nSee [H][].\n");
    }

    public function testResolvesInsideAListItem(): void
    {
        // The reported bug: indented source, so the old pre-scan never saw it.
        $this->assertResolves("- # H\n\nSee [H][].\n");
    }

    public function testDeclinesUnderABlockquote(): void
    {
        $this->assertLiteral("> # H\n>\n> See [H][].\n");
    }

    public function testDeclinesInEitherNestingOrder(): void
    {
        // A blockquote ANCESTOR declines, however deep and whichever way round.
        $this->assertLiteral(":::\n> # H\n:::\n\nSee [H][].\n");
        $this->assertLiteral("> :::\n> # H\n> :::\n\nSee [H][].\n");
    }

    public function testTheExplicitLabelFormFollowsTheSameRule(): void
    {
        // `[text][Label]` resolves against headings too (matching carve-js),
        // and had the same list-item hole.
        $this->assertStringContainsString('href="#H"', $this->html("- # H\n\nSee [t][H].\n"));
        $this->assertStringNotContainsString('href="#H"', $this->html("> # H\n\nSee [t][H].\n"));
    }

    public function testMatchingFoldsCaseAndCollapsesWhitespace(): void
    {
        $html = $this->html("# Getting Started\n\nSee [getting started][].\n");
        $this->assertStringContainsString('href="#Getting-Started"', $html);
    }

    public function testALinkDefinitionStillWins(): void
    {
        $html = $this->html("# H\n\n[H]: /wins\n\nSee [H][].\n");
        $this->assertStringContainsString('href="/wins"', $html);
        $this->assertStringNotContainsString('href="#H"', $html);
    }

    public function testAnUnmatchedLabelStaysLiteral(): void
    {
        $this->assertStringContainsString('[Nope][]', $this->html("# H\n\nSee [Nope][].\n"));
    }

    public function testAQuotedHeadingIsStillReachableByCrossReference(): void
    {
        // The non-regression that matters: declining the reference index must
        // not make the heading unaddressable. `</#id>` targets it by id rather
        // than by wording, so it still resolves.
        $this->assertStringContainsString('href="#H"', $this->html("> # H\n\nSee </#H>.\n"));
    }

    public function testAQuotedHeadingStillGetsAnIdAndDedupes(): void
    {
        $html = $this->html("# abc\n\n> # abc\n\n# abc\n");
        $this->assertStringContainsString('id="abc"', $html);
        $this->assertStringContainsString('id="abc-2"', $html);
        $this->assertStringContainsString('id="abc-3"', $html);
    }

    public function testTheIdCounterMatchesTheRendererAcrossDeclinedHeadings(): void
    {
        // A declined heading still consumes an id, because the renderer
        // numbers every heading in document order. Counting only the
        // registered ones shifts the counter and points the reference at the
        // QUOTED heading - resolving to the very thing R1 excludes, which is
        // worse than not resolving at all.
        $html = $this->html("> # H\n\n- # H\n\nSee [H][].\n");

        $this->assertStringContainsString('href="#H-2"', $html);
        $this->assertStringNotContainsString('href="#H"', str_replace('href="#H-2"', '', $html));
    }

    public function testTheSecondPassDoesNotDuplicateWarnings(): void
    {
        // The second pass re-runs the whole parse, so anything it accumulates
        // has to be reset first. A duplicated warning would be the visible
        // symptom of state surviving between passes.
        $converter = new CarveConverter(warnings: true);
        $converter->convert("- # H\n\nSee [H][] and [Nope][].\n");
        $messages = array_map(
            static fn (object $w): string => $w->getMessage(),
            $converter->getWarnings(),
        );
        $nope = array_filter($messages, static fn (string $m): bool => str_contains($m, 'Nope'));

        $this->assertCount(1, $nope, implode("\n", $messages));
    }
}
