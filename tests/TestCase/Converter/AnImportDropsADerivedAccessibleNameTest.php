<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#1500: an importer drops an attribute whose value equals
 * what the renderer DERIVES for that element, and keeps every other one.
 *
 * Re-importing a generated name makes it look authored, and PART 9 §12 writes a
 * name only where the author wrote NONE - so the imported copy wins on the next
 * render and the document can no longer be localized. Dropping it is free: the
 * renderer regenerates the same string.
 *
 * Matched on VALUE, not provenance. A name that DIFFERS from the derived one is
 * the author's and is kept, which is what keeps carve-php#1337 fixed.
 */
class AnImportDropsADerivedAccessibleNameTest extends TestCase
{
    protected function import(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    public function testAnUntitledAdmonitionsTypeNameIsDropped(): void
    {
        $carve = $this->import("<aside class=\"admonition note\" aria-label=\"Note\">\n<p>body</p>\n</aside>\n");

        $this->assertStringNotContainsString('aria-label', $carve);
        $this->assertStringContainsString('::: note', $carve);
    }

    public function testATabSetAndACodeGroupNameAreDropped(): void
    {
        $tabs = $this->import("<div class=\"tabs\" aria-label=\"Tabs\"><p>a</p></div>\n");
        $this->assertStringNotContainsString('aria-label', $tabs);

        $group = $this->import("<div class=\"code-group\" aria-label=\"Code examples\"><p>a</p></div>\n");
        $this->assertStringNotContainsString('aria-label', $group);
    }

    public function testADiagramFenceNameIsDropped(): void
    {
        $carve = $this->import("<pre class=\"mermaid\" aria-label=\"mermaid\">graph TD;</pre>\n");

        $this->assertStringNotContainsString('aria-label', $carve);
    }

    public function testAnIndexBackLinkNameIsDropped(): void
    {
        $carve = $this->import(
            "<ul class=\"index\">\n"
            . '<li>widget <a href="#idx-widget-1" class="index-backref" aria-label="Back to widget 1">↩</a> '
            . "<a href=\"#idx-widget-2\" class=\"index-backref\" aria-label=\"Back to widget 2\">↩</a></li>\n"
            . "</ul>\n",
        );

        $this->assertStringNotContainsString('aria-label', $carve);
    }

    /**
     * The whole point of the drop: the imported source has to stay localizable.
     */
    public function testTheImportedSourceStillFollowsTheLabelsMap(): void
    {
        $html = (new CarveConverter())->convert("::: note\nbody\n:::\n");
        $imported = (new HtmlToCarve())->convert($html);
        $german = (new CarveConverter(labels: ['admonitionNote' => 'Hinweis']))->convert($imported);

        $this->assertStringContainsString('aria-label="Hinweis"', $german);
        $this->assertStringNotContainsString('aria-label="Note"', $german);
    }

    /**
     * Dropping is free, which is why it needs no trade-off: the renderer writes
     * the same string back.
     */
    public function testDroppingRebuildsTheSameHtml(): void
    {
        $original = (new CarveConverter())->convert("::: note\nbody\n:::\n");
        $again = (new CarveConverter())->convert((new HtmlToCarve())->convert($original));

        $this->assertSame($original, $again);
    }

    /**
     * A name that DIFFERS is the author's. This is the case a provenance-blind
     * blanket drop would take, and carve-php#1337 records why that is a
     * regression rather than tidiness.
     */
    public function testAnAuthoredNameOnANamedConstructSurvives(): void
    {
        $carve = $this->import(
            "<aside class=\"admonition note\" aria-label=\"Wichtiger Hinweis\">\n<p>b</p>\n</aside>\n",
        );

        $this->assertStringContainsString('aria-label="Wichtiger Hinweis"', $carve);
    }

    public function testAnAuthoredNameOnAnUnnamedElementSurvives(): void
    {
        $carve = $this->import("<blockquote aria-label=\"Chorus\"><p>q</p></blockquote>\n");

        $this->assertStringContainsString('aria-label=Chorus', $carve);
    }

    public function testAnAuthoredTabSetNameSurvives(): void
    {
        $carve = $this->import("<div class=\"tabs\" aria-label=\"My tab set\"><p>x</p></div>\n");

        $this->assertStringContainsString('aria-label="My tab set"', $carve);
    }
}
