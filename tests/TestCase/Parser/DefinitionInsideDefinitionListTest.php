<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A definition written inside a definition list's `dd` is COLLECTED, and the
 * entry keeps no trace of it (carve-php#891, spec markup-carve/carve#801,
 * corpus 227).
 *
 * The `dd` rendered empty before this too - the block parser removed the line,
 * which is the visible half and was already right. What was missing is the
 * collection: nothing registered the definition, so the reference it feeds
 * stayed literal somewhere ELSE in the document. That is the shape this class of
 * bug always takes - silent where the definition was written, visible where it
 * was used.
 *
 * Three gates had to agree, and each was a separate omission:
 *
 * - the link-reference extractor's marker stripper knew `-`, `*` and ordered
 *   markers but not the description marker;
 * - the footnote prepass's container-prefix scan had the same gap;
 * - and that prepass then refused the line anyway, because its opener test asks
 *   what precedes the definition and what precedes a description is the `::`
 *   term line - neither blank nor a container.
 *
 * The neighbouring rule, and the reason the marker is not simply always
 * stripped: a `:` line with NO term above it is not a description at all. It is
 * paragraph text, and a definition in it defines nothing (corpus
 * `216-a-description-line-needs-a-term-above-it`).
 */
class DefinitionInsideDefinitionListTest extends TestCase
{
    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testALinkDefinitionInADescriptionIsCollected(): void
    {
        $html = $this->html(":: term\n:  [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('<a href="/u">t</a>', $html);
        $this->assertStringContainsString('<dd></dd>', $html);
    }

    public function testAFootnoteDefinitionInADescriptionIsCollected(): void
    {
        $html = $this->html(":: term\n:  [^f]: x\n\nsee[^f]\n");

        $this->assertStringContainsString('role="doc-noteref"', $html);
        $this->assertStringContainsString('<dd></dd>', $html);
    }

    public function testALinkDefinitionNeedsATermAboveIt(): void
    {
        // Corpus 216. Without a term the line is not a description, so the
        // definition-shaped content defines nothing and the line stays visible.
        $html = $this->html(":  [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('<p>:  [r]: /u</p>', $html);
        $this->assertStringContainsString('see [t][r]', $html);
        $this->assertStringNotContainsString('<a href="/u">', $html);
    }

    public function testAFootnoteDefinitionNeedsATermAboveIt(): void
    {
        $html = $this->html(":  [^f]: x\n\nsee[^f]\n");

        $this->assertStringNotContainsString('role="doc-noteref"', $html);
    }

    public function testASecondDescriptionInTheSameEntryCollectsToo(): void
    {
        // The entry is continued by a further description line, so the term is
        // not the only thing that can precede one.
        $html = $this->html(":: term\n:  a\n:  [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('<a href="/u">t</a>', $html);
    }

    public function testATermMarkerIsNotADescriptionMarker(): void
    {
        // `::` needs whitespace after a SINGLE colon to be a description, which
        // it does not have - so this is a term, and the line is its content.
        $html = $this->html(":: [r]: /u\n\nsee [t][r]\n");

        $this->assertStringNotContainsString('<a href="/u">t</a>', $html);
    }

    public function testAColonFenceIsNotADescriptionMarker(): void
    {
        $html = $this->html(":: term\n::: note\n[r]: /u\n:::\n\nsee [t][r]\n");

        // The fence opens a div; whether the definition inside it is collected
        // is a different rule, and what matters here is that the fence line was
        // not read as a description marker and did not become a `dd`.
        $this->assertStringNotContainsString('<dd>::: note</dd>', $html);
    }
}
