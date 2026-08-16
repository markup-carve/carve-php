<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An authored heading id survives the import of the engine's own
 * section-wrapper HTML, and adjacent sections stay separate blocks.
 *
 * The renderer moves a heading's id onto its `<section>`, authored and
 * generated alike; outside round-trip mode the importer dropped every one,
 * so `{#custom}` came back as a text-derived id and its anchors broke
 * (carve-php#1289). An id that matches the tracker's slug of the heading
 * text is generation and is left to regeneration; anything else is kept.
 * Adjacent sections also used to glue their headings into one line
 * (`## A## B`), because processSection() returned processBlock()'s trimmed
 * content bare (carve-php#1297).
 */
class ASectionIdImportsFromItsOwnHtmlTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'authored id and class' => ["{#custom .cls}\n## Heading Two\n"],
            'generated id regenerates' => ["## Plain Heading\n"],
            'duplicate headings' => ["## One\n\n## One\n"],
            'adjacent sections' => ["## A\n\n## B\n"],
            'authored id mid-document' => ["intro text\n\n{#custom}\n## Later Heading\n"],
            'authored id that looks like a dedup suffix' => ["{#a-2}\n## A\n"],
        ];
    }

    /**
     * @param string $source
     */
    #[DataProvider('sourceProvider')]
    public function testTheRenderedHtmlSurvivesTheImport(string $source): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert($source);
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame(
            $html,
            (new CarveConverter())->convert($imported),
            "importing the rendered HTML must reproduce it; imported source was:\n" . $imported,
        );
    }

    public function testTheAuthoredIdIsSpelledInTheImportedSource(): void
    {
        $html = (new CarveConverter())->convert("{#custom .cls}\n## Heading Two\n");
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertStringContainsString('{#custom .cls}', $imported);
    }

    public function testAGeneratedIdIsNotSpelledOut(): void
    {
        $html = (new CarveConverter())->convert("## Plain Heading\n");
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertStringNotContainsString('{#', $imported);
    }
}
