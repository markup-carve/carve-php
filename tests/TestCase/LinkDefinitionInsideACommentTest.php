<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A comment's body is OPAQUE. This engine already applied that to footnote
 * definitions and not to link reference definitions, so a `[r]: /u` written
 * inside `%%%` registered: invisible in the output and active in the link
 * table, which makes a reference elsewhere resolve against text the author
 * commented out (carve-php#778, markup-carve/carve#644).
 */
class LinkDefinitionInsideACommentTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testALinkDefinitionInsideACommentRegistersNothing(): void
    {
        $html = $this->converter->convert("%%%\n[r]: /u\n%%%\n[r][]");

        $this->assertStringNotContainsString('href', $html);
        $this->assertStringContainsString('[r][]', $html);
    }

    public function testAFootnoteDefinitionInsideACommentStillRegistersNothing(): void
    {
        $html = $this->converter->convert("%%%\n[^a]: note\n%%%\nsee[^a]");

        $this->assertStringNotContainsString('doc-endnotes', $html);
        $this->assertStringContainsString('see[^a]', $html);
    }

    public function testAnOrdinaryDefinitionOutsideACommentStillResolves(): void
    {
        $html = $this->converter->convert("[r]: /u\n\n[r][]");

        $this->assertStringContainsString('href="/u"', $html);
    }

    public function testAnUnterminatedFenceDoesNotSuppressTheRestOfTheDocument(): void
    {
        // An unterminated `%%%` is not a fenced comment - it degrades to a
        // single-line comment. Treating it as open would have swallowed every
        // later definition in the document.
        $html = $this->converter->convert("%%%\n[r]: /u\n\n[r][]");

        $this->assertStringContainsString('href="/u"', $html);
    }
}
