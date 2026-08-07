<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A cross-reference label is a derived-text expansion, and it is budgeted.
 *
 * `</#slug>` republishes the target heading's whole display text while costing
 * only the slug, so K references to one long heading emit `K * heading_len`
 * bytes. That is the abbreviation expansion's shape, so it charges the
 * abbreviation expansion's budget (carve-php#1061).
 */
class CrossReferenceLabelBudgetTest extends TestCase
{
    /**
     * A heading of mostly non-slug characters, so the slug (`A`) is far shorter
     * than the display text, plus $references references to it.
     */
    protected function amplificationSource(int $headingLength, int $references): string
    {
        return '# A' . str_repeat('!', $headingLength - 1) . "\n\n"
            . str_repeat('</#A> ', $references) . "\n";
    }

    /**
     * Budget = max(1000000, 8 * source length), plus what each reference pays
     * for itself (a degraded label, an anchor) and a slack term.
     */
    protected function ceiling(int $sourceLength, int $references): int
    {
        return max(1000000, 8 * $sourceLength) + 60 * $references + 10000;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function expandingTargetProvider(): array
    {
        return [
            'html' => ['create'],
            'markdown' => ['markdown'],
            'plain text' => ['plainText'],
            'ansi' => ['ansi'],
        ];
    }

    #[DataProvider('expandingTargetProvider')]
    public function testLabelExpansionStaysWithinTheBudget(string $factory): void
    {
        $source = $this->amplificationSource(10000, 1600);
        $ceiling = $this->ceiling(strlen($source), 1600);
        // The input must be able to overshoot for this test to mean anything.
        $this->assertGreaterThan(4 * $ceiling, 10000 * 1600);

        $output = CarveConverter::$factory()->convert($source);

        $this->assertLessThan($ceiling, strlen($output));
    }

    /**
     * The bound has to be on the RATIO, not on one measurement: unbudgeted,
     * output grows with the square of the input, so the ratio doubles with it.
     */
    public function testDoublingTheInputDoesNotMultiplyTheOutput(): void
    {
        $converter = CarveConverter::create();
        $small = $this->amplificationSource(5000, 800);
        $large = $this->amplificationSource(10000, 1600);

        $smallRatio = strlen($converter->convert($small)) / strlen($small);
        $largeRatio = strlen($converter->convert($large)) / strlen($large);

        $this->assertLessThan($smallRatio, $largeRatio);
    }

    /**
     * The degraded label is the AUTHORED target, the way an over-budget
     * abbreviation degrades to its plain key - not an empty string, and not the
     * unresolved `</#A>` source form.
     */
    public function testAnOverBudgetLabelDegradesToTheAuthoredTarget(): void
    {
        $html = CarveConverter::create()->convert($this->amplificationSource(10000, 1600));

        $this->assertStringContainsString('<a href="#A">A</a>', $html);
    }

    /**
     * Every target sizes the budget from the same document, so on an input
     * where 8 x length clears the 1 MB floor they all clip at the same place. A
     * target that never installed a budget would fall back to the floor and emit
     * far less, which is what the plain-text target did before it was given one.
     */
    public function testEveryTargetSizesTheBudgetFromTheSameDocument(): void
    {
        $source = $this->amplificationSource(50000, 50000);
        $this->assertGreaterThan(2000000, 8 * strlen($source));

        $html = strlen(CarveConverter::create()->convert($source));
        foreach (['markdown', 'plainText', 'ansi'] as $factory) {
            $ratio = strlen(CarveConverter::$factory()->convert($source)) / $html;
            $this->assertGreaterThan(0.75, $ratio, $factory . ' is not sharing one budget with html');
            $this->assertLessThan(1.25, $ratio, $factory . ' is not sharing one budget with html');
        }

        $plain = strlen(CarveConverter::plainText()->convert($source));
        $this->assertGreaterThan(2000000, $plain, 'the plain target clipped at the floor budget');
    }

    /**
     * The Carve target reproduces the author's document (PART 11 section 1,
     * markup-carve/carve#759): it re-emits `</#A>` rather than the label, so it
     * never amplified and must not gain a budget.
     */
    public function testTheCarveTargetIsUnchanged(): void
    {
        $source = $this->amplificationSource(10000, 1600);
        $output = CarveConverter::carve()->convert($source);

        $this->assertLessThan(strlen($source) + 100, strlen($output));
        $this->assertStringContainsString('</#A>', $output);
    }

    /**
     * CONTROL: an ordinary document is nowhere near the budget, so every label
     * renders in full on every target. If a mutation to the budget broke this,
     * it broke ordinary rendering.
     */
    public function testAnOrdinaryDocumentRendersEveryLabelInFull(): void
    {
        $source = "# The Long Heading Here\n\nsee </#the-long-heading-here> and </#the-long-heading-here>\n";

        foreach (['create', 'markdown', 'plainText', 'ansi'] as $factory) {
            $output = CarveConverter::$factory()->convert($source);
            $this->assertSame(3, substr_count($output, 'The Long Heading Here'), $factory);
        }
    }
}
