<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9R R1a: a definition line that folds into an open paragraph yields no
 * entry in any symbol table, and a probe that cannot afford to establish that
 * collects nothing either.
 *
 * `definitionMarkerOpensBlock` spends `strlen(before) * 2 + strlen(candidate)`
 * per candidate against an allowance that only grows linearly with the source,
 * so the cost crosses on any long enough run. Past the crossing it used to
 * answer "it opens a block", which collected a definition from a line it had
 * established nothing about - a document that grew silently started resolving
 * references its smaller self left literal (carve-php#1835).
 *
 * These run past the crossing on purpose. A shorter document exercises only the
 * affordable arm.
 */
class AnUnaffordableDefinitionProbeCollectsNothingTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * The shape carve-php#1835 measured: n lazy definition lines under an open
     * paragraph, then a reference to the last label.
     */
    private function lazyDefinitions(int $count): string
    {
        $source = "intro paragraph\n";
        for ($i = 0; $i < $count; $i++) {
            $source .= "- [d{$i}]: /u{$i}\n";
        }

        return $source . "\n[go][d" . ($count - 1) . "]\n";
    }

    public function testAnAffordableProbeLeavesTheLineLiteral(): void
    {
        // The control, below the crossing. Nothing is collected, so the
        // reference has no definition and renders as its source.
        $html = $this->converter->convert($this->lazyDefinitions(3));

        $this->assertStringNotContainsString('href="/u2"', $html);
        $this->assertStringContainsString('[go][d2]', $html);
    }

    public function testAnUnaffordableProbeAgreesWithTheAffordableOne(): void
    {
        // Past the crossing the answer must not change. Eight lines were
        // correct and nine were not, on the document the ticket measured.
        $html = $this->converter->convert($this->lazyDefinitions(40));

        $this->assertStringNotContainsString('href="/u39"', $html);
        $this->assertStringContainsString('[go][d39]', $html);
    }

    public function testNoAuthoredLineIsRemovedPastTheBudget(): void
    {
        // DOES NOT DISTINGUISH THE TWO BEHAVIORS ON THIS ENGINE, and is here
        // anyway. carve-php registered from a line it went on to render, so
        // both answers keep the text and this passes with the fix reverted.
        // carve-rs failed the same clause by DELETING the line instead
        // (carve-rs#1492), and nothing else here pins that it cannot happen.
        $html = $this->converter->convert($this->lazyDefinitions(200));

        for ($i = 0; $i < 200; $i++) {
            $this->assertStringContainsString("[d{$i}]: /u{$i}", $html);
        }

        $this->assertStringNotContainsString('<li></li>', $html);
    }

    public function testADefinitionAfterABlankLineStillResolves(): void
    {
        // The conservative fallback may decline a definition, so pin that it
        // does not decline one no probe is needed for: with no paragraph open
        // the line is a definition outright.
        $source = "intro paragraph\n\n[d]: /u\n\n[go][d]\n";

        $this->assertStringContainsString('href="/u"', $this->converter->convert($source));
    }
}
