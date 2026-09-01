<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9R R1a describes the probe as comparing the open last-child chains, on
 * the property that "a line that folds adds no node at any level, while a line
 * that opens anything changes a count".
 *
 * `definitionProbeChain` walks the LAST block child at each level, and a
 * collected definition is hoisted to document level with no block children of
 * its own. It was therefore always the last child, the walk stopped one step
 * past the document, and it never reached the container where the candidate
 * added its item - so the second marker-led definition in a run registered
 * nothing (carve-php#1849). The first one worked because it is what creates
 * the masking node.
 */
class ACollectedDefinitionDoesNotBlindTheProbeTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function markerDefinitions(int $count): string
    {
        $source = "# H\n";
        for ($i = 0; $i < $count; $i++) {
            $source .= "- [d{$i}]: /u{$i}\n";
        }

        return $source . "\n[go][d" . ($count - 1) . "]\n";
    }

    public function testTheFirstMarkerLedDefinitionRegisters(): void
    {
        // The control: this one always worked, because no hoisted node exists
        // yet when its probe runs.
        $this->assertStringContainsString(
            'href="/u0"',
            $this->converter->convert($this->markerDefinitions(1)),
        );
    }

    public function testTheSecondOneRegistersToo(): void
    {
        // The case the masking broke, and the minimum that reproduces it.
        $this->assertStringContainsString(
            'href="/u1"',
            $this->converter->convert($this->markerDefinitions(2)),
        );
    }

    public function testAShortRunRegistersThroughout(): void
    {
        // Every label in a run that stays inside the probe budget, so a fix
        // that only unmasked the second one would not pass this. Each label
        // needs its own reference: an unreferenced definition is dropped from
        // the output (PART 9R R2), so referencing only the last one would
        // assert nothing about the rest.
        $source = "# H\n";
        for ($i = 0; $i < 5; $i++) {
            $source .= "- [d{$i}]: /u{$i}\n";
        }
        $source .= "\n";
        for ($i = 0; $i < 5; $i++) {
            $source .= "[go{$i}][d{$i}]\n";
        }

        $html = $this->converter->convert($source);

        for ($i = 0; $i < 5; $i++) {
            $this->assertStringContainsString("href=\"/u{$i}\"", $html);
        }
    }

    public function testALazyDefinitionUnderAnOpenParagraphStillRegistersNothing(): void
    {
        // The near miss. Unmasking the chain must not start collecting a line
        // that genuinely folds: here a paragraph is open above it, so R1a's
        // first paragraph says it defines nothing and stays text.
        $html = $this->converter->convert("intro paragraph\n- [d]: /u\n\n[go][d]\n");

        $this->assertStringNotContainsString('href="/u"', $html);
        $this->assertStringContainsString('[go][d]', $html);
    }
}
