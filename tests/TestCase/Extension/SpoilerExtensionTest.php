<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use PHPUnit\Framework\TestCase;

class SpoilerExtensionTest extends TestCase
{
    protected function convert(string $djot): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new SpoilerExtension());

        return $converter->convert($djot);
    }

    public function testSummaryRendersInlineMarkup(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new SpoilerExtension());

        $html = $converter->convert("::: spoiler \"a *b*\"\nHidden.\n:::");

        $this->assertStringContainsString('<summary>a <strong>b</strong></summary>', $html);
    }

    public function testInlineRendersSpoilerSpan(): void
    {
        $this->assertStringContainsString(
            '<span class="spoiler">the butler did it</span>',
            $this->convert('Plot: :spoiler[the butler did it].'),
        );
    }

    public function testInlineMergesClassesAndStripsEventHandler(): void
    {
        // `spoiler` base class ahead of author classes; id after; onclick dropped
        // by the always-on attribute hardening.
        $this->assertStringContainsString(
            '<span class="spoiler big" id="s">x</span>',
            $this->convert(':spoiler[x]{#s .big onclick="y"}'),
        );
    }

    public function testInlineFallsBackToExtSpoilerWithoutExtension(): void
    {
        $converter = new CarveConverter();

        $this->assertStringContainsString(
            '<span class="ext-spoiler">x</span>',
            $converter->convert(':spoiler[x]'),
        );
    }

    public function testBlockRendersDetailsDisclosure(): void
    {
        $this->assertSame(
            "<details class=\"spoiler\">\n  <summary>Ending</summary>\n  <p>Everyone lives.</p>\n</details>",
            trim($this->convert("::: spoiler \"Ending\"\nEveryone lives.\n:::")),
        );
    }


    public function testBlockEmptyBodyKeepsBlankLine(): void
    {
        // An empty body renders as a single blank line, matching a core empty
        // container and carve-js / carve-rs. Regression for the collapsed line.
        $this->assertSame(
            "<details class=\"spoiler\">\n  <summary>Spoiler</summary>\n\n</details>",
            trim($this->convert("::: spoiler\n:::")),
        );
    }

    public function testBlockDefaultsSummaryToSpoiler(): void
    {
        $this->assertStringContainsString(
            "<details class=\"spoiler\">\n  <summary>Spoiler</summary>",
            $this->convert("::: spoiler\nHidden.\n:::"),
        );
    }

    public function testBlockFallsBackToDivWithoutExtension(): void
    {
        $converter = new CarveConverter();

        $this->assertStringContainsString(
            '<div class="spoiler">',
            $converter->convert("::: spoiler\nHidden.\n:::"),
        );
    }

    public function testBlockHardensDangerousAttributes(): void
    {
        $html = $this->convert("{onclick=\"alert(1)\" style=\"background:url(javascript:alert(1))\"}\n::: spoiler \"T\"\nx\n:::");

        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('background:url', $html);
        $this->assertStringContainsString('<details class="spoiler" style="">', $html);
    }
}
