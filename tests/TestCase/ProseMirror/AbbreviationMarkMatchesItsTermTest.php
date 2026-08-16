<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * An abbreviation MARK carries the expansion, not the term, so rebuilding the
 * document has to decide which spans were expanded from a definition and which
 * the author wrote by hand. Deciding on the expansion alone is not enough: two
 * different spans can share one.
 */
class AbbreviationMarkMatchesItsTermTest extends TestCase
{
    public function testASpanSharingAnExpansionWithADefinitionIsNotThatAbbreviation(): void
    {
        // `foo` is not `HTML`. Matched by expansion alone, the span was rebuilt
        // as the abbreviation the definition expands and came back as plain
        // `foo` - the span, its attribute and the markup around it all gone.
        $source = "*[HTML]: HyperText Markup Language\n\nHTML and [foo]{abbr=\"HyperText Markup Language\"}.\n";

        $pm = (new ProseMirrorRenderer())->render((new CarveConverter())->parse($source));
        $back = CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($pm));

        $this->assertSame($source, $back);
    }

    public function testATermTheDocumentDefinesComesBackAsTheDefinitionAndTheUse(): void
    {
        $source = "*[HTML]: HyperText Markup Language\n\nHTML rules.\n";

        $pm = (new ProseMirrorRenderer())->render((new CarveConverter())->parse($source));
        $back = CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($pm));

        $this->assertSame($source, $back);
    }

    public function testAnAuthoredAbbreviationSpanNeedsNoDefinitionAtAll(): void
    {
        $source = "A [term]{abbr=\"An expansion\"} here.\n";

        $pm = (new ProseMirrorRenderer())->render((new CarveConverter())->parse($source));
        $back = CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($pm));

        $this->assertSame($source, $back);
    }
}
