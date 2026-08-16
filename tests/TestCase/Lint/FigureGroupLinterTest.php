<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\FigureGroupLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The five PART 9 §4c rules (markup-carve/carve-php#1308). The positive rows
 * are the spec's own trigger samples - the validation page's rule table is
 * measured against them in the spec repo, so the same inputs firing here is
 * what cross-engine parity means. Message wording mirrors carve-js `lint.ts`.
 */
class FigureGroupLinterTest extends TestCase
{
    protected FigureGroupLinter $linter;

    protected function setUp(): void
    {
        $this->linter = new FigureGroupLinter();
    }

    /**
     * @param string $source
     *
     * @return array<string>
     */
    protected function rulesFor(string $source): array
    {
        return array_map(
            static fn ($warning): string => $warning->rule,
            $this->linter->lint($source),
        );
    }

    /**
     * The spec's trigger samples (tests/lint-rule-table-claims.test.mjs): each
     * must fire its rule. Other rules may fire beside it - the nested sample's
     * outer group is legitimately empty too, in every engine.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function triggerSamples(): array
    {
        return [
            'nested' => [
                FigureGroupLinter::RULE_FIGURE_GROUP_NESTED,
                ":::: figure\n::: figure\n![a](a.png)\n^ (a) A\n:::\n::::\n^ Figure #: G\n",
            ],
            'opener metadata' => [
                FigureGroupLinter::RULE_FIGURE_GROUP_OPENER_METADATA,
                "::: figure \"Title\"\n![a](a.png)\n^ (a) A\n:::\n",
            ],
            'panel number' => [
                FigureGroupLinter::RULE_FIGURE_GROUP_PANEL_NUMBER,
                "::: figure\n![a](a.png)\n^ Figure #: panel\n:::\n^ Figure #: G\n",
            ],
            'empty' => [
                FigureGroupLinter::RULE_FIGURE_GROUP_EMPTY,
                "::: figure\njust a paragraph\n:::\n^ Figure #: G\n",
            ],
            'single panel' => [
                FigureGroupLinter::RULE_FIGURE_GROUP_SINGLE_PANEL,
                "::: figure\n![a](a.png)\n^ (a) A\n:::\n^ Figure #: G\n",
            ],
        ];
    }

    #[DataProvider('triggerSamples')]
    public function testTheSpecTriggerSampleFiresItsRule(string $rule, string $source): void
    {
        $this->assertContains($rule, $this->rulesFor($source));
    }

    public function testACleanTwoPanelGroupReportsNothing(): void
    {
        $this->assertSame(
            [],
            $this->rulesFor("::: figure\n![a](a.png)\n^ (a) A\n\n![b](b.png)\n^ (b) B\n:::\n^ Figure #: G\n"),
        );
    }

    public function testANumberInTheGroupCaptionIsNotAPanelNumber(): void
    {
        // The `#` in the GROUP caption is exactly where the number belongs.
        $rules = $this->rulesFor("::: figure\n![a](a.png)\n^ (a) A\n\n![b](b.png)\n^ (b) B\n:::\n^ Figure #: G\n");

        $this->assertNotContains(FigureGroupLinter::RULE_FIGURE_GROUP_PANEL_NUMBER, $rules);
    }

    public function testATablePanelCaptionNumberIsReportedToo(): void
    {
        // A table keeps its caption beside its rows rather than among its
        // children; the rule reads both homes.
        $rules = $this->rulesFor("::: figure\n| a |\n|---|\n^ Table #: t\n\n![b](b.png)\n^ (b) B\n:::\n^ Figure #: G\n");

        $this->assertContains(FigureGroupLinter::RULE_FIGURE_GROUP_PANEL_NUMBER, $rules);
    }

    public function testAnOrdinaryContainerReportsNothing(): void
    {
        $this->assertSame([], $this->rulesFor("::: note\nbody\n:::\n"));
    }

    public function testAnAttributeLineClassIsNotAnOpenerKind(): void
    {
        // `{.figure}` above a bare `:::` is untyped: the class came from an
        // attribute line, not an opener word, so no rule fires.
        $this->assertSame([], $this->rulesFor("{.figure}\n:::\nbody\n:::\n"));
    }

    public function testALabeledOpenerReportsMetadataNotNesting(): void
    {
        $rules = $this->rulesFor("::: figure [g]\nBody.\n:::\n");

        $this->assertContains(FigureGroupLinter::RULE_FIGURE_GROUP_OPENER_METADATA, $rules);
        $this->assertNotContains(FigureGroupLinter::RULE_FIGURE_GROUP_NESTED, $rules);
    }

    public function testTheFindingCarriesThePositionOfItsNode(): void
    {
        $warnings = $this->linter->lint(":::: figure\n::: figure\n![a](a.png)\n^ (a) A\n:::\n::::\n^ Figure #: G\n");

        $nested = null;
        foreach ($warnings as $warning) {
            if ($warning->rule === FigureGroupLinter::RULE_FIGURE_GROUP_NESTED) {
                $nested = $warning;
            }
        }

        $this->assertNotNull($nested);
        $this->assertSame(2, $nested->line, 'the nested finding sits on the inner opener, not the group');
    }

    public function testTheMessagesMirrorTheParityReference(): void
    {
        // carve-js `lint.ts` is the wording reference; a drifted message is a
        // cross-engine report that reads differently for the same document.
        $warnings = $this->linter->lint("::: figure\njust a paragraph\n:::\n^ Figure #: G\n");

        $this->assertCount(1, $warnings);
        $this->assertSame(
            'This "::: figure" group holds no captionable panel; '
                . 'the panels wrapper renders around the preserved content only.',
            $warnings[0]->message,
        );
    }
}
