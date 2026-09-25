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
 * Each definition is a block node in the editor document. The occurrence is a
 * `carveAbbreviation` mark carrying its title.
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

    public function testTheDefinitionRidesOnItsOwnNode(): void
    {
        $document = (new CarveConverter())->parse("*[HTML]: HyperText Markup Language\n\nThe HTML spec.\n");
        $payload = (new ProseMirrorRenderer())->render($document);
        $this->assertIsArray($payload['content']);
        $this->assertIsArray($payload['content'][0]);

        $this->assertSame(
            ['abbr' => 'HTML', 'expansion' => 'HyperText Markup Language'],
            $payload['content'][0]['attrs'] ?? null,
        );
        $this->assertArrayNotHasKey('attrs', $payload);
    }

    /**
     * The first definition's position relative to body content sets the flag.
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
