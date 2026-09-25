<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\TestCase;

/**
 * `::: footnotes` placement is a core feature (no extension needed): the marker
 * relocates the endnotes section to that spot; its absence is byte-identical to
 * the default end-of-document rendering.
 */
class FootnotesPlacementTest extends TestCase
{
    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testFlushesEndnotesAtTheMarker(): void
    {
        $out = $this->html("Intro[^a].\n\n::: footnotes\n:::\n\n## After\n\n[^a]: note a\n");
        $this->assertLessThan(strpos($out, '<h2'), strpos($out, 'role="doc-endnotes"'));
        $this->assertStringContainsString('<li id="fn1">', $out);
    }

    public function testIncludesFootnotesReferencedAfterTheMarker(): void
    {
        // The flush is "all footnotes", not just those seen before the marker.
        $out = $this->html("A[^a].\n\n::: footnotes\n:::\n\n## After\n\nB[^b].\n\n[^a]: a\n\n[^b]: b\n");
        $this->assertStringContainsString('<li id="fn1">', $out);
        $this->assertStringContainsString('<li id="fn2">', $out);
        // Exactly one endnotes section.
        $this->assertSame(1, substr_count($out, 'role="doc-endnotes"'));
    }

    public function testNoMarkerRendersEndnotesAtTheEnd(): void
    {
        $out = $this->html("Intro[^a].\n\n## After\n\n[^a]: note a\n");
        $this->assertLessThan(strpos($out, 'role="doc-endnotes"'), strpos($out, '<h2'));
    }

    public function testDegradesWhenNoFootnotes(): void
    {
        // The ordinary typed-div rendering, which is what carve-js emits here
        // too. It used to be a hand-written `<div class="footnotes"></div>`,
        // shorter than the div any other empty container renders.
        $out = $this->html("Plain.\n\n::: footnotes\n:::\n");
        $this->assertStringContainsString("<div class=\"footnotes\">\n\n</div>", $out);
        $this->assertStringNotContainsString('doc-endnotes', $out);
    }

    public function testADegradedMarkerKeepsItsTitleAndLabel(): void
    {
        // Authored text never vanishes: the div a marker degrades to IS the
        // placed element, so both tokens render inside it, and it takes no
        // naming attribute (role `generic` prohibits one) - CARVE-P9-072.
        $out = $this->html("::: footnotes \"Notes\" [End]\nbody\n:::\n");
        $this->assertStringContainsString(
            "<div class=\"footnotes\">\n  <p class=\"admonition-title\">Notes</p>\n"
                . "  <p class=\"div-label\">End</p>\n  <p>body</p>\n</div>",
            $out,
        );
        $this->assertStringNotContainsString('aria-label', $out);
    }

    public function testAPlacingMarkerNamesTheSectionWithItsTitle(): void
    {
        $out = $this->html("Intro[^a].\n\n::: footnotes \"Notes\" [End]\n:::\n\n[^a]: note a\n");
        $this->assertStringContainsString(
            "<section role=\"doc-endnotes\" aria-labelledby=\"adm-1\">\n"
                . "  <p class=\"admonition-title\" id=\"adm-1\">Notes</p>\n"
                . "  <p class=\"div-label\">End</p>\n  <hr>",
            $out,
        );
    }

    public function testTheSectionTakesItsIdBeforeItsOwnChildren(): void
    {
        // Document order, not render order: the marker's title precedes a titled
        // admonition written inside the marker, so it takes `adm-1`.
        $out = $this->html("a[^1]\n\n::: footnotes \"Notes\"\n::: note \"Inner\"\nx\n:::\n:::\n\n[^1]: body\n");
        $this->assertStringContainsString('<section role="doc-endnotes" aria-labelledby="adm-1">', $out);
        $this->assertStringContainsString('<p class="admonition-title" id="adm-2">Inner</p>', $out);
    }

    public function testAnUntitledMarkerRendersWhatNoMarkerRenders(): void
    {
        $marker = $this->html("Intro[^a].\n\n::: footnotes\n:::\n\n[^a]: note a\n");
        $this->assertStringContainsString('<section role="doc-endnotes" aria-label="Footnotes">', $marker);
    }

    public function testSecondMarkerDoesNotDuplicateTheSection(): void
    {
        $out = $this->html("X[^a].\n\n::: footnotes\n:::\n\n::: footnotes\n:::\n\n[^a]: a\n");
        $this->assertSame(1, substr_count($out, 'role="doc-endnotes"'));
    }

    public function testNestedInDefinitionNeverLeaksSentinel(): void
    {
        // A `::: footnotes` inside a footnote definition renders as an ordinary
        // div, never the internal placement sentinel.
        $out = $this->html("X[^a].\n\n[^a]: ::: footnotes\n    :::\n");
        $this->assertStringNotContainsString('footnotes-placement', $out);
        $this->assertStringNotContainsString("\x00", $out);
        $this->assertStringContainsString('<div class="footnotes">', $out);
    }

    public function testPreservesAuthoredContentInsidePlacementBlock(): void
    {
        $out = $this->html("X[^a].\n\n::: footnotes\nNotes below:\n:::\n\n[^a]: note\n");
        $this->assertStringContainsString('Notes below:', $out);
        $this->assertLessThan(
            (int)strpos($out, 'role="doc-endnotes"'),
            (int)strpos($out, 'Notes below:'),
        );
    }

    /**
     * A `::: footnotes` placement directive is a childless-by-design carrier,
     * not an empty container: a profile that denies nothing must not prune it
     * and fall back to the default end-of-document placement (carve-php #505).
     */
    public function testKeepsThePlacementUnderAProfileThatDeniesNothing(): void
    {
        $source = "Intro[^a] and[^b].\n\n::: footnotes\n:::\n\n## After\n\nMore text.\n\n"
            . "[^a]: first note\n\n[^b]: second note\n";

        $unfiltered = $this->html($source);

        $filtered = new CarveConverter();
        $filtered->setProfile(Profile::full());

        $this->assertSame(
            $unfiltered,
            $filtered->convert($source),
            'a profile that denies nothing relocated the footnotes placement anyway',
        );
    }
}
