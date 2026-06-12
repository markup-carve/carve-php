<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Markdown cross-reference links use the pandoc/kramdown `{#id}` heading-id
 * convention so `[label](#id)` points at a real anchor.
 */
class MarkdownCrossRefLinkTest extends TestCase
{
    private function md(string $djot): string
    {
        return CarveConverter::markdown()->convert($djot);
    }

    public function testReferencedHeadingEmitsIdAndLink(): void
    {
        $out = $this->md("# Installation\n\nSee </#installation> for setup.\n");

        $this->assertStringContainsString('# Installation {#installation}', $out);
        $this->assertStringContainsString('[Installation](#installation)', $out);
    }

    public function testUnreferencedHeadingHasNoId(): void
    {
        $out = $this->md("# Intro\n\nplain text.\n");

        $this->assertStringContainsString('# Intro', $out);
        $this->assertStringNotContainsString('{#', $out);
    }

    public function testForwardReferenceEmitsIdAndLink(): void
    {
        $out = $this->md("See </#setup>.\n\n# Setup\n");

        $this->assertStringContainsString('[Setup](#setup)', $out);
        $this->assertStringContainsString('# Setup {#setup}', $out);
    }

    public function testExplicitHeadingIdIsUsedForTheAnchor(): void
    {
        $out = $this->md("# Title {#foo}\n\nSee </#foo>.\n");

        $this->assertStringContainsString('# Title {#foo}', $out);
        $this->assertStringContainsString('[Title](#foo)', $out);
    }

    public function testFigureCaptionCrossrefStaysPlainText(): void
    {
        // A numbered figure/table has no markdown anchor, so its crossref renders
        // the resolved label as plain text (no link, no {#id}).
        $out = $this->md("{#fig}\n![x](a.jpg)\n^ Figure #: A\n\nSee </#fig>.\n");

        $this->assertStringContainsString('Figure 1', $out);
        $this->assertStringNotContainsString('[Figure 1](#fig)', $out);
        $this->assertStringNotContainsString('{#fig}', $out);
    }

    public function testParenInIdIsNotAValidIdentifier(): void
    {
        // An id is a grammar identifier (letter/digit/`_`/`-`/`:`); a `)` is
        // not, so `{#foo)}` is not an attribute block at all and stays literal
        // heading text (§14). No id is set, so the crossref does not resolve.
        $out = $this->md("# Title {#foo)}\n\nSee </#foo)>.\n");

        $this->assertStringContainsString('{\\#foo)}', $out);
        $this->assertStringNotContainsString('[Title]', $out);
    }

    public function testMultiLineHeadingIsFlattenedWithIdOnTheHeadingLine(): void
    {
        // A markdown heading is single-line, so a lazy-continuation carve heading
        // is flattened; the `{#id}` stays on the heading line so the link anchors.
        $out = $this->md("# Foo\nbar\n\nSee </#foo-bar>.\n");

        $this->assertStringContainsString('# Foo bar {#foo-bar}', $out);
        $this->assertStringContainsString('[Foo bar](#foo-bar)', $out);
        $this->assertStringNotContainsString("bar {#foo-bar}\n\n# ", $out);
    }

    public function testUnresolvedReferenceStaysLiteral(): void
    {
        $out = $this->md("See </#nope>.\n");

        $this->assertStringContainsString('</#nope>', $out);
    }
}
