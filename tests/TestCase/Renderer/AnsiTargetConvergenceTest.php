<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Two ANSI-target divergences from carve#352, both of them this engine alone.
 */
class AnsiTargetConvergenceTest extends TestCase
{
    private CarveConverter $converter;

    private AnsiRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->renderer = new AnsiRenderer();
    }

    private function render(string $source): string
    {
        return $this->renderer->render($this->converter->parse($source));
    }

    /**
     * Heading levels 1-5 all use BRIGHT colours, so level 6 does too. Plain white
     * broke the engine's own series and was the only heading colour the other two
     * engines disagreed with (corpus 02).
     */
    public function testLevelSixHeadingUsesTheBrightSeries(): void
    {
        $out = $this->render("###### H6\n");

        $this->assertStringContainsString(AnsiRenderer::FG_BRIGHT_WHITE, $out);
        $this->assertStringNotContainsString(AnsiRenderer::FG_WHITE, $out);
    }

    /**
     * An autolink's visible text IS its target, so there is nothing to append. For
     * an EMAIL autolink the `mailto:` was added by the parser rather than written
     * by the author, and the grammar's DISPLAY TEXT rule puts that scheme on the
     * href only -- showing it to the reader put it exactly where it must not appear
     * (corpus 03-links-5, 36-autolinks).
     */
    public function testAnEmailAutolinkDoesNotShowItsMailtoScheme(): void
    {
        $out = $this->render("Write <hello@example.com>.\n");

        $this->assertStringContainsString('hello@example.com', $out);
        $this->assertStringNotContainsString('mailto:', $out);
    }

    public function testAUrlAutolinkDoesNotRepeatItself(): void
    {
        $out = $this->render("Visit <https://example.com>.\n");

        $this->assertSame(1, substr_count($out, 'https://example.com'));
    }

    /**
     * The target is still shown when the author wrote something different from it,
     * which is the case the append exists for.
     */
    public function testAnOrdinaryLinkStillShowsItsTarget(): void
    {
        $out = $this->render("See [the docs](https://example.com/docs).\n");

        $this->assertStringContainsString('the docs', $out);
        $this->assertStringContainsString('https://example.com/docs', $out);
    }
}
