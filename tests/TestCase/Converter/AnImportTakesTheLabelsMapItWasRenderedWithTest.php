<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#1500 step 2. Matching the English defaults alone catches
 * only a document rendered in English; one rendered with a `labels` map carries
 * a value no default can recognize, so its generated name was kept and laundered
 * into source - and a translated document is exactly the one §16a's map exists
 * to serve.
 *
 * The host that rendered the HTML knows the map it used, so handing the same map
 * to the importer closes it. A caller that passes nothing is unaffected.
 */
class AnImportTakesTheLabelsMapItWasRenderedWithTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    protected array $de = [
        'admonitionNote' => 'Hinweis',
        'tabsGroup' => 'Registerkarten',
        'codeGroup' => 'Codebeispiele',
        'endnotes' => 'Fußnoten',
    ];

    public function testATranslatedNameIsDroppedWhenTheMapIsSupplied(): void
    {
        $html = (new CarveConverter(labels: $this->de))->convert("::: note\nbody\n:::\n");
        $this->assertStringContainsString('aria-label="Hinweis"', $html);

        $carve = (new HtmlToCarve(labels: $this->de))->convert($html);

        $this->assertStringNotContainsString('aria-label', $carve);
        $this->assertStringContainsString('::: note', $carve);
    }

    /**
     * The behavior this replaces, pinned so the difference stays visible: with
     * no map the importer cannot tell the translated name from an authored one,
     * and keeps it. That is the residue, not a bug.
     */
    public function testWithoutTheMapTheTranslatedNameIsStillKept(): void
    {
        $html = (new CarveConverter(labels: $this->de))->convert("::: note\nbody\n:::\n");

        $carve = (new HtmlToCarve())->convert($html);

        $this->assertStringContainsString('aria-label=Hinweis', $carve);
    }

    public function testTheEnglishDefaultIsStillDroppedWithNoMap(): void
    {
        $html = (new CarveConverter())->convert("::: note\nbody\n:::\n");

        $this->assertStringNotContainsString('aria-label', (new HtmlToCarve())->convert($html));
    }

    /**
     * A supplied map does not make the importer blind to the defaults: a map
     * naming ONE key leaves every other construct matched as before, because
     * the host's map is layered over the defaults rather than replacing them.
     */
    public function testAPartialMapStillMatchesTheDefaultsForEveryOtherKey(): void
    {
        $html = (new CarveConverter(labels: ['admonitionNote' => 'Hinweis']))->convert(
            "::: note\nn\n:::\n\n::: warning\nw\n:::\n",
        );

        $carve = (new HtmlToCarve(labels: ['admonitionNote' => 'Hinweis']))->convert($html);

        // The mapped one and the unmapped one both lose their generated name.
        $this->assertStringNotContainsString('aria-label', $carve);
        $this->assertStringContainsString('::: note', $carve);
        $this->assertStringContainsString('::: warning', $carve);
    }

    /**
     * An authored name still survives, map or no map - the value differs from
     * the derived one either way (carve-php#1337).
     */
    public function testAnAuthoredNameSurvivesWithTheMapSupplied(): void
    {
        $carve = (new HtmlToCarve(labels: $this->de))->convert(
            "<aside class=\"admonition note\" aria-label=\"Wichtiger Hinweis\">\n<p>b</p>\n</aside>\n",
        );

        $this->assertStringContainsString('aria-label="Wichtiger Hinweis"', $carve);
    }

    public function testTheImportedSourceStaysLocalizable(): void
    {
        $html = (new CarveConverter(labels: $this->de))->convert("::: note\nbody\n:::\n");
        $imported = (new HtmlToCarve(labels: $this->de))->convert($html);

        // Re-rendered under a DIFFERENT map, the name follows the new one.
        $again = (new CarveConverter(labels: ['admonitionNote' => 'Note']))->convert($imported);

        $this->assertStringContainsString('aria-label="Note"', $again);
        $this->assertStringNotContainsString('Hinweis', $again);
    }
}
