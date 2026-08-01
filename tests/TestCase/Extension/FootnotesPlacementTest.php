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
        $out = $this->html("Plain.\n\n::: footnotes\n:::\n");
        $this->assertStringContainsString('<div class="footnotes"></div>', $out);
        $this->assertStringNotContainsString('doc-endnotes', $out);
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
