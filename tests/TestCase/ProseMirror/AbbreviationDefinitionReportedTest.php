<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * An abbreviation definition was dropped by the bridge in SILENCE, so every
 * expansion in the document stopped working and `droppedTypes()` said nothing
 * (carve-php#519, class 4).
 *
 * It is reported rather than carried. The definitions live on the Document as a
 * map - this engine expands them at render time, so there is no node in the
 * tree to walk - and holding them would need an attribute on the doc node that
 * the editor's schema defines. This side must not emit a name CarveKit never
 * registered, so naming the loss is what a caller can act on.
 */
class AbbreviationDefinitionReportedTest extends TestCase
{
    protected function report(string $source): array
    {
        $renderer = new ProseMirrorRenderer();
        $renderer->render((new CarveConverter())->parse($source));

        return $renderer->droppedTypes();
    }

    public function testADroppedDefinitionIsReported(): void
    {
        $dropped = $this->report("*[HTML]: HyperText Markup Language\n\nThe HTML spec.\n");

        $this->assertArrayHasKey('abbreviation_def', $dropped);
        $this->assertNotSame('', $dropped['abbreviation_def'], 'the report must say why');
    }

    /**
     * The report has to stay quiet when there is nothing to say, or a caller
     * asserting on an empty report can never use it.
     */
    public function testADocumentWithoutDefinitionsReportsNothing(): void
    {
        $this->assertSame([], $this->report("No abbreviations here.\n"));
    }

    /**
     * The loss itself is unchanged - this is a report, not a fix - and pinning
     * it keeps the two honest about each other. If the bridge ever learns to
     * carry a definition, this test fails and the report must go with it.
     */
    public function testTheDefinitionIsStillLostAcrossTheRoundTrip(): void
    {
        $source = "*[HTML]: HyperText Markup Language\n\nThe HTML spec.\n";
        $document = (new CarveConverter())->parse($source);

        $proseMirror = (new ProseMirrorRenderer())->render($document);
        $back = (new ProseMirrorToCarve())->convert($proseMirror);

        $this->assertSame([], $back->getAbbreviations());
        $this->assertNotSame([], $document->getAbbreviations(), 'the fixture had none to begin with');
    }
}
