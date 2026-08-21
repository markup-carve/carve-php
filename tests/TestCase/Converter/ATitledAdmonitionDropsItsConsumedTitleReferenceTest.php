<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §12 names a TITLED admonition with `aria-labelledby` pointing at the id
 * on its own `<p class="admonition-title">`. The import CONSUMES that element -
 * its text becomes the opener's quoted title - so the id goes with it and the
 * reference is left naming nothing.
 *
 * Keeping it was worse than noise. §12 writes a name only where the author wrote
 * NONE, so the stale attribute - by then indistinguishable from an authored one -
 * suppressed the correct name on the next render AND pointed at an id no
 * document had.
 *
 * A reference pointing anywhere ELSE names an element this import is not
 * consuming, so it is the author's and it stays. Same discrimination as the
 * generated `scope` on a `<th>`.
 */
class ATitledAdmonitionDropsItsConsumedTitleReferenceTest extends TestCase
{
    protected function import(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    public function testTheGeneratedSelfReferenceIsDropped(): void
    {
        $carve = $this->import(
            "<aside class=\"admonition warning\" aria-labelledby=\"adm-1\">\n"
            . "<p class=\"admonition-title\" id=\"adm-1\">Careful</p>\n"
            . "<p>body</p>\n"
            . "</aside>\n",
        );

        $this->assertStringNotContainsString('aria-labelledby', $carve);
        $this->assertStringContainsString('::: warning "Careful"', $carve);
    }

    /**
     * The defect this closes, stated end to end: the imported source has to
     * RE-RENDER to a named admonition whose name resolves, and it did neither.
     */
    public function testTheImportedSourceRerendersToAResolvingName(): void
    {
        $original = "::: warning \"Careful\"\nbody\n:::\n";
        $html = (new CarveConverter())->convert($original);
        $reimported = (new HtmlToCarve())->convert($html);
        $again = (new CarveConverter())->convert($reimported);

        // The title carries the id the aside points at - not one without the
        // other, which is what the stale attribute produced.
        $this->assertMatchesRegularExpression(
            '/<aside[^>]*aria-labelledby="([^"]+)"/',
            $again,
        );
        preg_match('/<aside[^>]*aria-labelledby="([^"]+)"/', $again, $m);
        $this->assertStringContainsString('id="' . $m[1] . '"', $again);
    }

    public function testAnAuthoredReferenceElsewhereSurvives(): void
    {
        $carve = $this->import(
            "<aside class=\"admonition note\" aria-labelledby=\"my-heading\">\n<p>body</p>\n</aside>\n",
        );

        $this->assertStringContainsString('aria-labelledby=my-heading', $carve);
    }

    /**
     * The near miss: a title IS present, and the reference names something else.
     * A blanket drop keyed on "this element has a title" would take this too.
     */
    public function testAnAuthoredReferenceSurvivesEvenWhenATitleIsPresent(): void
    {
        $carve = $this->import(
            "<aside class=\"admonition note\" aria-labelledby=\"elsewhere\">\n"
            . "<p class=\"admonition-title\" id=\"adm-1\">T</p>\n"
            . "<p>body</p>\n"
            . "</aside>\n",
        );

        $this->assertStringContainsString('aria-labelledby=elsewhere', $carve);
        $this->assertStringContainsString('::: note "T"', $carve);
    }

    /**
     * An authored `aria-label` is untouched: carve-php#1337 established that
     * dropping it is an accessibility regression applied in bulk to exactly the
     * documents an importer runs on, and this change does not revisit that.
     */
    public function testAnAuthoredAriaLabelOnAnUnnamedElementSurvives(): void
    {
        $carve = $this->import("<blockquote aria-label=\"Chorus\"><p>q</p></blockquote>\n");

        $this->assertStringContainsString('aria-label=Chorus', $carve);
    }
}
