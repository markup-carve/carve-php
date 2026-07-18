<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A footnote definition carries its body on the marker line: `[^label]:`
 * followed by a space and inline content (grammar PART 9 §16; corpus 132).
 * A bare `[^label]:` line is an ordinary paragraph, and a following indented
 * line folds into it as paragraph text.
 */
class FootnoteDefinitionBodyTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testEmptyMarkerLineIsNotADefinition(): void
    {
        $this->assertSame(
            "<p>Use [^a].</p>\n<p>[^a]:\nFirst</p>\n",
            $this->converter->convert("Use [^a].\n\n[^a]:\n  First\n"),
        );
    }

    public function testMarkerLineWithTrailingWhitespaceOnlyIsNotADefinition(): void
    {
        $this->assertSame(
            "<p>Use [^a].</p>\n<p>[^a]:</p>\n",
            $this->converter->convert("Use [^a].\n\n[^a]: \n"),
        );
    }

    public function testDefinitionWithInlineBodyStillCollectsContinuations(): void
    {
        $html = $this->converter->convert("Use [^a].\n\n[^a]: First\n\n  Second\n");

        $this->assertStringContainsString('<li id="fn1">', $html);
        $this->assertStringContainsString('<p>First</p>', $html);
        $this->assertStringContainsString('Second<a href="#fnref1" role="doc-backlink">↩</a>', $html);
    }
}
