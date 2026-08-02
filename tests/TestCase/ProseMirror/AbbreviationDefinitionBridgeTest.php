<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * Abbreviation definitions survive the ProseMirror bridge (carve-php#519).
 *
 * They are DOCUMENT state rather than children, so they never reached
 * renderBlocks and vanished without being reported by droppedTypes() or
 * degradedTypes(). The occurrence itself always survived - it is a
 * `carveAbbreviation` mark carrying its title - which is what made the loss
 * hard to see: the round trip produced a document that still looked right until
 * it was written back out, at which point no `*[ABBR]: ...` line existed and
 * every expansion in the document stopped working.
 *
 * These assert on CANONICAL CARVE and on HTML, not on HTML alone. The existing
 * corpus round-trip test compares rendered HTML, and for this class of defect
 * that check cannot fail on the bridge payload alone - the mark keeps the
 * rendering identical right up until the source is rewritten.
 */
class AbbreviationDefinitionBridgeTest extends TestCase
{
    protected function roundTrip(string $carve): string
    {
        $document = (new CarveConverter())->parse($carve);
        $payload = (new ProseMirrorRenderer())->render($document);

        return CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($payload));
    }

    public function testTheDefinitionSurvivesTheRoundTrip(): void
    {
        $carve = "*[HTML]: HyperText Markup Language\n\nThe HTML spec.\n";

        $this->assertStringContainsString('*[HTML]: HyperText Markup Language', $this->roundTrip($carve));
    }

    public function testTheExpansionStillRendersAfterTheRoundTrip(): void
    {
        $carve = "*[HTML]: HyperText Markup Language\n\nThe HTML spec.\n";
        $converter = new CarveConverter();

        $this->assertSame(
            trim($converter->convert($carve)),
            trim($converter->convert($this->roundTrip($carve))),
        );
    }

    public function testTheDefinitionRidesOnTheDocNodeAttrs(): void
    {
        $document = (new CarveConverter())->parse("*[HTML]: HyperText Markup Language\n\nThe HTML spec.\n");
        $payload = (new ProseMirrorRenderer())->render($document);

        $this->assertSame(
            ['HTML' => 'HyperText Markup Language'],
            $payload['attrs']['carveAbbreviations'] ?? null,
        );
    }

    /**
     * The flag decides whether the definitions are written before the body or
     * after it, and it cannot be recovered from the map, so it travels with it.
     */
    public function testTheOrderingFlagTravelsWithTheDefinitions(): void
    {
        $before = (new CarveConverter())->parse("*[HTML]: HyperText Markup Language\n\nThe HTML spec.\n");
        $payload = (new ProseMirrorRenderer())->render($before);

        $this->assertSame(
            $before->hasAbbreviationsBeforeBody(),
            (new ProseMirrorToCarve())->convert($payload)->hasAbbreviationsBeforeBody(),
        );
    }

    public function testADocumentWithNoAbbreviationsCarriesNoAttrs(): void
    {
        $document = (new CarveConverter())->parse("Plain text.\n");
        $payload = (new ProseMirrorRenderer())->render($document);

        $this->assertArrayNotHasKey('attrs', $payload);
    }

    public function testSeveralDefinitionsAllSurvive(): void
    {
        $carve = "*[HTML]: HyperText Markup Language\n*[CSS]: Cascading Style Sheets\n\nHTML and CSS.\n";
        $written = $this->roundTrip($carve);

        $this->assertStringContainsString('*[HTML]: HyperText Markup Language', $written);
        $this->assertStringContainsString('*[CSS]: Cascading Style Sheets', $written);
    }
}
