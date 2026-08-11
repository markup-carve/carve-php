<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §24 C3: a colon fence opened on a MARKER LINE takes a body only from
 * the item's content column. With the body flush left the fence never forms -
 * the line is outside the item body, and with no blank it lazily continues the
 * item's paragraph, taking the opener with it as literal text.
 *
 * The item's collected stream had lost that geometry: the opener sat at the
 * stream's own column 0 with the body under it, which is exactly the shape the
 * div parser builds a container from, so this engine produced an admonition
 * where carve-js, carve-rs and the executable spec all render two literal
 * lines (carve-php#748).
 */
class MarkerLineFenceBodyColumnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAFlushLeftBodyLeavesTheFenceLiteral(): void
    {
        $html = $this->converter->convert("- ::: note\nbody\n:::");

        $this->assertSame(
            "<ul>\n  <li>::: note\nbody</li>\n</ul>\n<div>\n</div>\n",
            $html,
        );
    }

    public function testAFlushLeftBodyWithNoCloserIsLiteralToo(): void
    {
        $html = $this->converter->convert("- ::: note\nbody");

        $this->assertSame("<ul>\n  <li>::: note\nbody</li>\n</ul>\n", $html);
    }

    public function testABodyAtTheContentColumnStillNests(): void
    {
        $html = $this->converter->convert("- ::: note\n  body\n  :::");

        $this->assertStringContainsString('<aside class="admonition note">', $html);
        $this->assertStringContainsString('<p>body</p>', $html);
    }

    public function testABodyAfterABlankAtTheContentColumnStillNests(): void
    {
        $html = $this->converter->convert("- ::: note\n\n  body\n  :::");

        $this->assertStringContainsString('<aside class="admonition note">', $html);
    }

    public function testAnOpenerAloneIsStillAContainer(): void
    {
        // Nothing follows, so nothing contradicts the opener.
        $html = $this->converter->convert('- ::: note');

        $this->assertStringContainsString('<aside class="admonition note">', $html);
    }

    public function testAnOrderedMarkerBehavesTheSame(): void
    {
        $html = $this->converter->convert("1. ::: warning\n   body\n   :::");

        $this->assertStringContainsString('<aside class="admonition warning">', $html);
    }
}
