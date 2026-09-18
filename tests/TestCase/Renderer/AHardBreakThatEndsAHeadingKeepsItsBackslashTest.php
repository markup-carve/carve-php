<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `heading = hashes " "+ inline+ lineEnd` and `hardBreak = "\" (newline | &end)`,
 * so a heading may end in a hard break and `## x\` re-reads as `<h2>x<br></h2>`.
 *
 * Dropping it cost more than the break: `## \` became `##`, which is no heading
 * at all, so the formatter rewrote a heading into a paragraph and broke PART 11
 * §1's `parse(fmt(x)) == parse(x)` (markup-carve/carve-php#2169). Every other
 * break in a heading has nowhere to go, because a heading ends at its newline,
 * and collapses to a space the way carve-js and carve-rs write it.
 */
class AHardBreakThatEndsAHeadingKeepsItsBackslashTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function fixedPoints(): array
    {
        return [
            'text then break' => ["## x\\\n"],
            'break alone' => ["## \\\n"],
            'space then break' => ["## x \\\n"],
            'deeper level' => ["#### x\\\n"],
            'break after a closed span' => ["## *x*\\\n"],
        ];
    }

    #[DataProvider('fixedPoints')]
    public function testTheFormatterLeavesItAlone(string $source): void
    {
        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    #[DataProvider('fixedPoints')]
    public function testItStillRendersAsAHeading(string $source): void
    {
        $this->assertStringContainsString('<h', (new CarveConverter())->convert($source));
    }

    public function testAnInteriorBreakCollapsesToASpace(): void
    {
        $this->assertSame("<section id=\"a-b\">\n  <h2>a b</h2>\n</section>\n", (new CarveConverter())->convert("## a b\n"));
    }

    /**
     * An even backslash run before the newline is literal, not a break marker.
     */
    public function testALiteralBackslashEndingAHeadingIsNotReadAsABreak(): void
    {
        $this->assertSame("## x\\\\\n", CarveConverter::toCarve("## x\\\\\n"));
    }
}
