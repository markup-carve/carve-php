<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * `+` is the list-continuation marker (PART 9 section 17), not a bullet, so
 * `+ text` is ordinary paragraph text. The abbreviation collector's list-item
 * guard treated it as a bullet and opened a phantom item, which suppressed the
 * definition on the NEXT line: the term expanded nowhere and `fmt` dropped the
 * line. carve-js and carve-rs both collect it.
 */
class AbbreviationUnderContinuationMarkerTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    public function testExpandsATermDefinedUnderAContinuationMarker(): void
    {
        $html = $this->converter->convert("+ continuation\n*[AB]: x\n\nAB here\n");

        $this->assertStringContainsString('<abbr title="x">AB</abbr>', $html);
    }

    public function testKeepsTheDefinitionLineThroughTheCanonicalWriter(): void
    {
        $carve = $this->converter->toCarve("+ continuation\n*[AB]: x\n");

        $this->assertStringContainsString('*[AB]: x', $carve);
    }

    /**
     * The control that bounds the change: a REAL bullet still opens an item, so
     * a definition-shaped line inside it stays item content and defines
     * nothing. Reverting the pattern to include `+` leaves this test passing,
     * which is what makes it a bound rather than a second proof.
     */
    public function testARealBulletStillSuppressesTheDefinition(): void
    {
        $html = $this->converter->convert("- item\n*[AB]: x\n\nAB here\n");

        $this->assertStringNotContainsString('<abbr', $html);
    }

    public function testAnOrderedMarkerStillSuppressesTheDefinition(): void
    {
        $html = $this->converter->convert("1. item\n*[AB]: x\n\nAB here\n");

        $this->assertStringNotContainsString('<abbr', $html);
    }

    /**
     * A blank line after the marker already worked, so this pins the shape the
     * fix must not change.
     */
    public function testABlankLineAfterTheMarkerStillWorks(): void
    {
        $html = $this->converter->convert("+ continuation\n\n*[AB]: x\n\nAB here\n");

        $this->assertStringContainsString('<abbr title="x">AB</abbr>', $html);
    }
}
