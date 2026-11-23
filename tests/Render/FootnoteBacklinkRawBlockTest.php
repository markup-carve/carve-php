<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-091: a raw block of another format renders nothing (C2-H-054), so it does not host the backlinks.
 */
final class FootnoteBacklinkRawBlockTest extends TestCase
{
    public function testBacklinkJoinsTheParagraphBeforeADroppedRawBlock(): void
    {
        $html = Carve::toHtml("x[^1]\n\n[^1]: a\n    ```=latex\n    \\x\n    ```\n");

        $this->assertStringContainsString(
            '<p>a<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>',
            $html,
        );
        $this->assertStringNotContainsString('<p><a href="#fnref1"', $html);
    }

    public function testSeveralDroppedRawBlocksAndSeveralReferences(): void
    {
        $source = "x[^1] y[^1]\n\n[^1]: a\n\n    ```=latex\n    \\x\n    ```\n\n    ```=typst\n    t\n    ```\n";

        $this->assertStringContainsString('<p>a<a href="#fnref1" role="doc-backlink"', Carve::toHtml($source));
    }

    public function testAnHtmlRawBlockStillTakesItsOwnBacklinkParagraph(): void
    {
        $html = Carve::toHtml("x[^1]\n\n[^1]: a\n    ```=html\n    <b>h</b>\n    ```\n");

        $this->assertStringContainsString("\n      <p><a href=\"#fnref1\" role=\"doc-backlink\"", $html);
    }
}
