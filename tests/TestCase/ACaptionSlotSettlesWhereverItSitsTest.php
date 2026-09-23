<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A held caption slot settles at any depth, and only where the finished
 * document still holds it (carve-php#2230).
 *
 * The slot used to carry the container and the index it was seen at. The walk
 * re-parses subtrees, so two thirds of the slots in a 321 KB document named a
 * container nothing else referenced any more: settling wrote into a detached
 * tree, and the reference kept that tree alive for the rest of the parse.
 *
 * Settling now walks the finished document, so these cases pin that a slot is
 * found through the container it actually ended up in.
 */
class ACaptionSlotSettlesWhereverItSitsTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAtDocumentLevel(): void
    {
        $html = $this->converter->convert("see[^a]\n\n![a][ok]\n^ cap\n\n[ok]: /p.png\n\n[^a]: note\n");

        $this->assertStringContainsString('<figcaption>cap</figcaption>', $html);
    }

    public function testInsideAQuote(): void
    {
        $html = $this->converter->convert("see[^a]\n\n> ![a][ok]\n> ^ cap\n\n[ok]: /p.png\n\n[^a]: note\n");

        $this->assertMatchesRegularExpression('#<blockquote>\s*<figure>#', $html);
        $this->assertStringContainsString('<figcaption>cap</figcaption>', $html);
    }

    public function testInsideAListItem(): void
    {
        $html = $this->converter->convert("see[^a]\n\n- ![a][ok]\n  ^ cap\n\n[ok]: /p.png\n\n[^a]: note\n");

        $this->assertMatchesRegularExpression('#<li>\s*<figure>#', $html);
    }

    public function testInsideADiv(): void
    {
        $html = $this->converter->convert("see[^a]\n\n::: box\n![a][ok]\n^ cap\n:::\n\n[ok]: /p.png\n\n[^a]: note\n");

        $this->assertMatchesRegularExpression('#<div class="box">\s*<figure>#', $html);
    }

    public function testTwoContainersDeep(): void
    {
        $html = $this->converter->convert("see[^a]\n\n> - ![a][ok]\n>   ^ cap\n\n[ok]: /p.png\n\n[^a]: note\n");

        $this->assertMatchesRegularExpression('#<blockquote>\s*<ul>\s*<li>\s*<figure>#', $html);
    }

    public function testAnUnresolvedReferenceHandsEveryLineBack(): void
    {
        $html = $this->converter->convert("see[^a]\n\n![a][missing]\n^ cap\n\n[^a]: note\n");

        $this->assertStringNotContainsString('<figure>', $html);
        $this->assertStringContainsString('^ cap', $html);
    }
}
