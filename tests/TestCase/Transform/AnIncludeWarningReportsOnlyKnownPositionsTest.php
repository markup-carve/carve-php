<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\ParseWarning;
use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeExpander;
use PHPUnit\Framework\TestCase;

class AnIncludeWarningReportsOnlyKnownPositionsTest extends TestCase
{
    private function warning(string $source, bool $trackPositions): ParseWarning
    {
        $converter = new CarveConverter();
        if ($trackPositions) {
            $converter->getParser()->enablePositionTracking();
        }
        $expander = new IncludeExpander(
            resolver: new FilesystemIncludeResolver(__DIR__),
            currentPath: 'index.crv',
            extensions: $converter->getExtensions(),
        );
        $converter->render($converter->transform($converter->parse($source), $expander));

        $warnings = $expander->getWarnings();
        $this->assertCount(1, $warnings);

        return $warnings[0];
    }

    public function testAStandaloneDirectiveReportsItsTrackedPosition(): void
    {
        $warning = $this->warning("Intro.\n\nMore.\n\n{{ missing.crv }}\n", true);

        $this->assertSame(5, $warning->getLine());
        $this->assertSame(1, $warning->getColumn());
        $this->assertStringContainsString('at line 5, column 1', (string)$warning);
    }

    public function testAnUntrackedDirectiveOmitsItsPosition(): void
    {
        $warning = $this->warning("Intro.\n\nMore.\n\n{{ missing.crv }}\n", false);

        $this->assertSame(0, $warning->getLine());
        $this->assertSame(0, $warning->getColumn());
        $this->assertStringNotContainsString('at line', (string)$warning);
    }

    public function testAnInlineDirectiveKeepsItsTrackedColumn(): void
    {
        $warning = $this->warning("Intro.\n\nMore.\n\nBefore {{ missing.crv }} after.\n", true);

        $this->assertSame(5, $warning->getLine());
        $this->assertSame(8, $warning->getColumn());
    }
}
