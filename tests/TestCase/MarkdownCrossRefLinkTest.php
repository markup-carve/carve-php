<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Document;
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

        $this->assertStringContainsString('# Installation {#Installation}', $out);
        $this->assertStringContainsString('[Installation](#Installation)', $out);
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

        $this->assertStringContainsString('[Setup](#Setup)', $out);
        $this->assertStringContainsString('# Setup {#Setup}', $out);
    }

    public function testCollapsedReferenceToHeadingEmitsTargetHeadingId(): void
    {
        $out = $this->md("See [name][]\n\n# Name");

        $this->assertSame("See [name](#Name)\n\n# Name {#Name}\n", $out);
    }

    public function testExplicitHeadingIdIsUsedForTheAnchor(): void
    {
        // The explicit id comes from a preceding block-attribute line
        // (djot-strict: a heading line carries no trailing attribute block).
        $out = $this->md("{#foo}\n# Title\n\nSee </#foo>.\n");

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
        // not, so the preceding block-attribute line `{#foo)}` is not a valid
        // attribute block and stays literal (§14). No id is set on the heading,
        // so the crossref does not resolve.
        $out = $this->md("{#foo)}\n# Title\n\nSee </#foo)>.\n");

        // `#` goes bare under PART 11 section 8a M1b: it is not adjacent to another
        // `#` on the emitted line.
        $this->assertStringContainsString('{#foo)}', $out);
        $this->assertStringNotContainsString('[Title]', $out);
    }

    public function testHeadingBeneathWhichProseSitsKeepsItsIdOnTheHeadingLine(): void
    {
        // A carve heading is single-line now, so the prose beneath it is a
        // paragraph in both languages; the `{#id}` stays on the heading line so
        // the link anchors, and the id derives from the heading line alone.
        $out = $this->md("# Foo\nbar\n\nSee </#foo>.\n");

        $this->assertStringContainsString('# Foo {#Foo}', $out);
        $this->assertStringContainsString('[Foo](#Foo)', $out);
        $this->assertStringContainsString("\n\nbar\n", $out);
    }

    public function testUnresolvedReferenceStaysLiteral(): void
    {
        $out = $this->md("See </#nope>.\n");

        $this->assertStringContainsString('</#nope>', $out);
    }

    /**
     * A stored tree whose whole link text is ONE text node.
     *
     * Parsing never produces that shape here - it splits the run around the
     * marker - so a control that has to see the marker beside other text in one
     * node has to come in through the decoder. PART 12 §1a coalesces adjacent
     * runs, so this is what a document that made a round trip looks like.
     */
    private function linkTextDocument(string $value): Document
    {
        return (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'link',
                            'href' => '/v',
                            'children' => [
                                ['type' => 'text', 'value' => $value],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testUnresolvedReferenceInsideLinkTextStaysLiteralToo(): void
    {
        // A crossref inside a link would render as a nested anchor, so the
        // resolver flattens it to a Text node before any renderer sees it. That
        // is the one path on which the marker never reached renderHeadingRef(),
        // so M1e escaped its `<` and one construct came out spelled two ways.
        $this->assertSame("a [t</#nope>](/u) b\n", $this->md("a [t</#nope>](/u) b\n"));
    }

    public function testTheMarkerIsSpelledTheSameWhereverItStands(): void
    {
        // The rule, asserted as a rule rather than as two byte strings: the
        // spelling of the emitted marker does not depend on its position.
        $insideLink = $this->md("a [t</#nope>](/u) b\n");
        $outsideLink = $this->md("a </#nope> b\n");

        $this->assertStringContainsString('</#nope>', $outsideLink);
        $this->assertStringContainsString('</#nope>', $insideLink);
        $this->assertStringNotContainsString('\\<', $insideLink);
    }

    public function testTheReferenceLinkSpellingIsUnescapedAsWell(): void
    {
        // Corpus 313, the document that surfaced this
        // (markup-carve/carve#1147). The reference resolves, so the link is
        // written out in its inline form with the declined marker in its text.
        $this->assertSame("a [t</#}>](/u) b\n", $this->md("a [t</#}>][r] b\n\n[r]: /u\n"));
    }

    public function testTheTargetInsideTheMarkerStillTakesTheHtmlPass(): void
    {
        // The writer's own `</#` and `>` stay literal; the id between them is
        // author content and does not. `</#a<script>` emitted verbatim is a live
        // tag in commonmark 0.31.2 and marked 18.0.9 alike, which is why the
        // carve-out cannot simply pass the match through.
        $out = $this->md("a [t</#a<script>x](/u) b\n");

        $this->assertSame("a [t</#a&lt;script>x](/u) b\n", $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function testAnAmpersandInTheTargetIsEscapedInsideALinkToo(): void
    {
        $this->assertSame("a [t</#a&amp;b>x](/u) b\n", $this->md("a [t</#a&b>x](/u) b\n"));
    }

    public function testTextBesideTheMarkerInTheSameNodeIsStillEscaped(): void
    {
        // The control that keeps this a carve-out rather than "a text node
        // holding a marker is emitted as it stands". `<b>` is a real hazard -
        // it opens a tag in both readers - and M1e still escapes it, in the
        // SAME node the exempt marker sits in.
        //
        // Ingested rather than parsed, which is also why the scan is not
        // anchored: PART 12 §1a coalesces adjacent runs, so a stored tree can
        // put the marker in the middle of a longer text node where parsing
        // gives it one of its own.
        $document = $this->linkTextDocument('t<b> </#nope> u');

        $this->assertSame(
            "[t\\<b> </#nope> u](/v)\n",
            CarveConverter::markdown()->render($document),
        );
    }

    public function testAnIncompleteMarkerInLinkTextIsStillEscaped(): void
    {
        // The second control, ingested for the same reason as the one above:
        // parsing splits `t</#nope` into `t</` and `#nope`, so the run the
        // pattern has to reject never reaches the carve-out in one piece.
        //
        // `</#nope` never closes, so it is not the crossref
        // production and gets no exemption - a carve-out keyed on `</#` alone
        // would leave this bare.
        $document = $this->linkTextDocument('t</#nope u');

        $this->assertSame(
            "[t\\</#nope u](/v)\n",
            CarveConverter::markdown()->render($document),
        );
    }
}
