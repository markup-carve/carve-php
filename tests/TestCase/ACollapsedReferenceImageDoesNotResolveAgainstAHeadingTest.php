<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\ReferenceLinter;
use PHPUnit\Framework\TestCase;

/**
 * The implicit heading fallback for collapsed references applies to links only.
 *
 * PART 11 R1 makes a heading a candidate for a collapsed reference LINK. An
 * image resolves against link definitions, so `![Plan][]` under `# Plan` stays
 * literal where `[Plan][]` resolves (markup-carve/carve-php#2900). Both the
 * render and the `fmt --migrate` rewrite followed the fallback, and the rewrite
 * is the half that edits the author's file.
 *
 * Render expectations are measured against the executable spec at the revision
 * `tests/spec` is pinned to (`scripts/spec/layout.mjs` into
 * `scripts/spec/html.mjs`), never read back from this engine. The link rows are
 * the controls: the fallback is correct for them and wrong only for images.
 */
class ACollapsedReferenceImageDoesNotResolveAgainstAHeadingTest extends TestCase
{
    private function html(string $source): string
    {
        return trim((new CarveConverter())->convert($source));
    }

    private function migrate(string $source): string
    {
        return (new ReferenceLinter())->rewriteCaseOnlyReferences($source);
    }

    public function testACollapsedImageStaysLiteralAboveAMatchingHeading(): void
    {
        $this->assertSame(
            "<section id=\"Plan\">\n  <h1>Plan</h1>\n  <p>![Plan][]</p>\n</section>",
            $this->html("# Plan\n\n![Plan][]\n"),
        );
    }

    /**
     * A definition is what an image resolves against, so the same reference
     * with one behind it still becomes an image.
     */
    public function testADefinitionStillResolvesTheImage(): void
    {
        $this->assertSame(
            "<section id=\"Plan\">\n  <h1>Plan</h1>\n  <img src=\"/p.png\" alt=\"Plan\">\n</section>",
            $this->html("# Plan\n\n[Plan]: /p.png\n\n![Plan][]\n"),
        );
    }

    /**
     * The control. The fallback is correct here, so an over-correction that
     * dropped it for links fails this row.
     */
    public function testACollapsedLinkStillResolvesAgainstTheHeading(): void
    {
        $this->assertSame(
            "<section id=\"Plan\">\n  <h1>Plan</h1>\n  <p><a href=\"#Plan\">Plan</a></p>\n</section>",
            $this->html("# Plan\n\n[Plan][]\n"),
        );
    }

    /**
     * A full reference and a shortcut were never on the fallback path; pinned
     * so a later widening is visible.
     */
    public function testAFullReferenceImageIsUnaffected(): void
    {
        $this->assertSame(
            "<section id=\"Plan\">\n  <h1>Plan</h1>\n  <p>![alt][Plan]</p>\n</section>",
            $this->html("# Plan\n\n![alt][Plan]\n"),
        );
    }

    public function testAShortcutImageIsUnaffected(): void
    {
        $this->assertSame(
            "<section id=\"Plan\">\n  <h1>Plan</h1>\n  <p>![Plan]</p>\n</section>",
            $this->html("# Plan\n\n![Plan]\n"),
        );
    }

    public function testMigrateDoesNotOfferAHeadingToAnImage(): void
    {
        $this->assertSame(
            "# Plan\n\n![plan][]\n",
            $this->migrate("# Plan\n\n![plan][]\n"),
        );
    }

    /**
     * The migrate control: a LINK is still rewritten to the heading's exact
     * spelling, which is what CARVE-P9R-010 asks for.
     */
    public function testMigrateStillRewritesACaseOnlyLink(): void
    {
        $this->assertSame(
            "# Plan\n\n[Plan][]\n",
            $this->migrate("# Plan\n\n[plan][]\n"),
        );
    }

    /**
     * And a case-only LINK DEFINITION is still offered to an image, because
     * that is a definition match rather than the heading fallback.
     */
    public function testMigrateStillRewritesAnImageAgainstADefinition(): void
    {
        $this->assertSame(
            "[Plan]: /p.png\n\n![Plan][]\n",
            $this->migrate("[Plan]: /p.png\n\n![plan][]\n"),
        );
    }
}
