<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AnAttachedNoteFenceKeepsDefinitionShapedPayloadTest extends TestCase
{
    public function testAnOpenFenceKeepsADefinitionShapedLineAsPayload(): void
    {
        $source = "[^f]: n\n+\n```\na\n[^z]: z\nb\n\nsee[^f]\n";

        self::assertSame(
            '<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n"
            . '<section role="doc-endnotes" aria-label="Footnotes">' . "\n"
            . "  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n</p>\n"
            . "      <pre><code>a\n[^z]: z\nb\n</code></pre>\n"
            . '      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>' . "\n"
            . "    </li>\n  </ol>\n</section>",
            trim((new CarveConverter())->convert($source)),
        );
    }
}
