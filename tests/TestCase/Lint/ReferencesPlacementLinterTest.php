<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Lint\ReferencesPlacementLinter;
use PHPUnit\Framework\TestCase;

class ReferencesPlacementLinterTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "See [@x].\n\n> ::: references\n> :::\n\n[@x]: Source\n";

    public function testReportsAContainedMarkerWhenCitationsAreEnabled(): void
    {
        $warnings = (new ReferencesPlacementLinter())->lint(self::SOURCE, ['extensions' => ['citations']]);
        $this->assertCount(1, $warnings);
        $this->assertSame(ReferencesPlacementLinter::RULE, $warnings[0]->rule);
        $this->assertSame(3, $warnings[0]->line);
        $this->assertSame(3, $warnings[0]->column);
    }

    public function testStaysSilentWithoutCitations(): void
    {
        $this->assertSame([], (new ReferencesPlacementLinter())->lint(self::SOURCE));
    }

    public function testAcceptsTheRegisteredExtensionObject(): void
    {
        $warnings = (new ReferencesPlacementLinter())->lint(self::SOURCE, [
            'extensions' => [new CitationsExtension()],
        ]);
        $this->assertCount(1, $warnings);
    }

    public function testStaysSilentForATopLevelMarker(): void
    {
        $source = "# Title\n\nSee [@x].\n\n::: references\n:::\n\n[@x]: Source\n";
        $this->assertSame([], (new ReferencesPlacementLinter())->lint($source, ['extensions' => ['citations']]));
    }

    public function testReportsTheNestedMarkerBesideATopLevelMarker(): void
    {
        $source = "See [@x].\n\n> ::: references\n> :::\n\n::: references\n:::\n\n[@x]: Source\n";
        $this->assertCount(1, (new ReferencesPlacementLinter())->lint($source, ['extensions' => ['citations']]));
    }

    public function testListAndFootnoteBodiesContainTheMarker(): void
    {
        $sources = [
            "- ::: references\n  :::\n",
            "Note[^a].\n\n[^a]: body\n\n    ::: references\n    :::\n",
        ];
        foreach ($sources as $source) {
            $warnings = (new ReferencesPlacementLinter())->lint($source, ['extensions' => ['citations']]);
            $this->assertCount(1, $warnings, $source);
            $this->assertSame(ReferencesPlacementLinter::RULE, $warnings[0]->rule);
        }
    }

    public function testIgnoresAClassOnAnUntypedDiv(): void
    {
        $source = "> {.references}\n> :::\n> :::\n";
        $this->assertSame([], (new ReferencesPlacementLinter())->lint($source, ['extensions' => ['citations']]));
    }
}
