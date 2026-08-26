<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A definition written inside a description is written back on that line.
 *
 * carve#805: collecting a definition out of a definition-list description
 * empties the `dd`, and an empty description has no source spelling - a bare
 * `:` line re-parses into the term above it. So the writer emitted a document
 * that says something else, and `to_html(fmt(x)) == to_html(x)` failed
 * (carve-php#903).
 *
 * The ruling keeps the definition node in the tree at its source position, so
 * the writer has somewhere to put it back: on the description line the author
 * wrote it on. carve-js#748 did this first; this is the port.
 */
class DefinitionWrittenBackOnItsDescriptionLineTest extends TestCase
{
    protected function fmt(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testALinkDefinitionIsWrittenBackOnItsOwnLine(): void
    {
        $source = ":: term\n: [r]: /u\n\nsee [t][r]\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testAFootnoteDefinitionIsWrittenBackOnItsOwnLine(): void
    {
        $source = ":: term\n: [^f]: x\n\nsee[^f]\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testTheDocumentStillSaysTheSameThing(): void
    {
        // PART 11 §1, which is the property that actually broke: the bare `:`
        // re-parsed into the term, so the rendered document changed.
        foreach ([":: term\n: [r]: /u\n\nsee [t][r]\n", ":: term\n: [^f]: x\n\nsee[^f]\n"] as $source) {
            $this->assertSame($this->html($source), $this->html($this->fmt($source)));
        }
    }

    public function testItIsNotWrittenTwice(): void
    {
        // The other half: once it is emitted on the description line, the
        // hoisted copy must not be emitted again at document level.
        $out = $this->fmt(":: term\n: [r]: /u\n\nsee [t][r]\n");

        $this->assertSame(1, substr_count($out, '[r]: /u'));
    }

    public function testAnOrdinaryDescriptionIsUnaffected(): void
    {
        // The control: a description with real content still round-trips, so a
        // pass above cannot mean descriptions stopped being written at all.
        $source = ":: term\n: body\n";

        $this->assertSame($source, $this->fmt($source));
    }
}
