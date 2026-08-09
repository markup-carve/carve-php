<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A caption folds a following PLAIN line, and nothing else.
 *
 * A definition, a comment and a block-attribute line all render nothing, so
 * none of them is caption text. This engine folded every one of them in -
 * publishing `[A]: }` as literal caption text, and a footnote definition twice
 * over (as caption text AND as an endnote). carve-js and carve-rs drop all five
 * (carve-php#688, carve#510).
 */
class CaptionStopsAtInvisibleLineTest extends TestCase
{
    private function caption(string $source): string
    {
        $html = (new CarveConverter())->convert($source);
        preg_match('/<figcaption>(.*?)<\/figcaption>/s', $html, $m);

        return $m[1] ?? '';
    }

    public function testALinkDefinitionDoesNotJoinTheCaption(): void
    {
        $this->assertSame('p', $this->caption(">\n^ p\n[A]: /u\n"));
    }

    public function testAFootnoteDefinitionDoesNotJoinTheCaption(): void
    {
        $html = (new CarveConverter())->convert(">\n^ p\n[^f]: t\n");

        $this->assertStringContainsString('<figcaption>p</figcaption>', $html);
    }

    public function testAnAbbreviationDefinitionDoesNotJoinTheCaption(): void
    {
        $this->assertSame('p', $this->caption(">\n^ p\n*[A]: x\n"));
    }

    public function testACommentDoesNotJoinTheCaption(): void
    {
        $this->assertSame('p', $this->caption(">\n^ p\n%% c\n"));
    }

    public function testABlockAttributeLineDoesNotJoinTheCaption(): void
    {
        $this->assertSame('p', $this->caption(">\n^ p\n{.c}\n"));
    }

    public function testAPlainLineStillFolds(): void
    {
        $this->assertSame("p\nmore", $this->caption(">\n^ p\nmore\n"));
    }

    public function testALinkDefinitionBetweenATableAndCaptionPreventsAttachment(): void
    {
        $html = (new CarveConverter())->convert("| a | b |\n[a]: /u\n^ cap\n");

        $this->assertStringNotContainsString('<caption>', $html);
        $this->assertStringContainsString('<p>^ cap</p>', $html);
    }
}
