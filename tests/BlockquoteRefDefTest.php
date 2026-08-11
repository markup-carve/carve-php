<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A reference-link definition placed inside a blockquote (`> [r]: /u`)
 * must populate the global ref map so a `[x][r]` anywhere in the
 * document resolves to it. The blockquote inner parser was already
 * suppressing the line from output; only the first-pass collection
 * was missing it.
 */
class BlockquoteRefDefTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testBlockquoteNestedDefResolves(): void
    {
        $html = $this->converter->convert("see [x][r].\n\n> [r]: /u");
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testForwardRefIntoBlockquoteDefResolves(): void
    {
        // The def appears AFTER the reference: still order-independent.
        $html = $this->converter->convert("[x][r] here.\n\n> [r]: /u");
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testNestedBlockquoteDefResolves(): void
    {
        $html = $this->converter->convert("[x][r]\n\n> > [r]: /u");
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testTopLevelDefStillWorks(): void
    {
        // Regression: stripping `>` markers must not break top-level defs.
        $html = $this->converter->convert("see [x][r].\n\n[r]: /u");
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testListItemRefDefResolvesGlobally(): void
    {
        $html = $this->converter->convert("[x][r] here.\n\n- [r]: /u");
        $this->assertStringContainsString('href="/u"', $html);
    }

    public function testIndentedQuoteLikeLineIsNotCollected(): void
    {
        // `>` must sit at column 0 to count as a blockquote marker. An
        // indented `    > [r]: /u` is paragraph / code continuation, not
        // a blockquoted reference definition.
        $html = $this->converter->convert("[x][r] here.\n\n    > [r]: /u");
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    public function testRefDefInsideFencedCodeIsNotCollected(): void
    {
        // Code samples must stay opaque: a `[r]: /u` (or `> [r]: /u`)
        // shown inside ``` is a literal sample, not a real definition.
        $src = "```\n> [r]: /u\n```\n\n[x][r]";
        $html = $this->converter->convert($src);
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    public function testContinuationMustStayInsideTheBlockquote(): void
    {
        // `> [r]:` followed by a top-level (non-blockquote) line must
        // NOT absorb that line into the quoted definition's URL.
        $html = $this->converter->convert("[x][r]\n\n> [r]:\n  /u");
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    public function testMultiLineBlockquoteDefDoesNotResolve(): void
    {
        // Reference definitions are single-line: a destination on a
        // CONTINUATION line is never gathered into the def. `> [r]:` with the
        // URL on the next line is therefore NOT a definition; both lines are
        // literal prose inside the blockquote (matches carve-js / carve-rs).
        $html = $this->converter->convert(
            "[x][r]\n\n> [r]:\n>   /u",
        );
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    public function testTwoDefsInSameBlockquoteResolve(): void
    {
        $src = "[a][one] and [b][two]\n\n> [one]: /1\n> [two]: /2";
        $html = $this->converter->convert($src);
        $this->assertStringContainsString('<a href="/1">a</a>', $html);
        $this->assertStringContainsString('<a href="/2">b</a>', $html);
    }

    /**
     * `>> [r]: /u` opens NO block quote. The grammar spells a quote line as
     * `'>', (newline | (space, inline_content, newline))`, so the second `>`
     * is content of nothing and the line is prose; the spec's validation table
     * names the shape `blockquote-marker-without-space` and says so outright.
     *
     * This used to assert the opposite, on a stale "matching carve-js" note
     * from before the space became required. It was pinning HALF a bug: the
     * definition prepass read the line through a looser marker rule than the
     * block parser, so the same document printed `<p>&gt;&gt; [r]: /u</p>` AND
     * resolved a link off it (markup-carve/carve-php#961). A nested quote is
     * written `> > [r]: /u`, which {@see self::testNestedBlockquoteDefResolves}
     * covers.
     */
    public function testRefDefInsideNoSpaceNestedBlockquoteIsNotCollected(): void
    {
        $html = $this->converter->convert("[x][r]\n\n>> [r]: /u");

        $this->assertStringNotContainsString('href="/u"', $html);
        $this->assertStringContainsString('<p>&gt;&gt; [r]: /u</p>', $html);
    }

    /**
     * The same shape one marker down, and the repro that opened
     * markup-carve/carve-php#961: `>[r]: /u` printed as a paragraph while the
     * prepass harvested it as a working definition, so the document showed the
     * definition AND resolved a link from it.
     */
    public function testAQuoteMarkerWithNoSpaceDefinesNothing(): void
    {
        $html = $this->converter->convert(">[r]: /u\n\n[link][r]\n");

        $this->assertStringContainsString('<p>&gt;[r]: /u</p>', $html);
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    /**
     * A tab after the marker is the same refusal. Distinct from the case above
     * because the retired loose rule left the tab in place, which the
     * definition scanner then rejected as indentation - so this shape read as
     * prose either way and is a CONTROL for the collapse.
     */
    public function testAQuoteMarkerFollowedByATabDefinesNothing(): void
    {
        $html = $this->converter->convert(">\t[r]: /u\n\n[link][r]\n");

        $this->assertStringNotContainsString('href="/u"', $html);
    }

    /**
     * The prepass fence tracker reads the same marker rule, and reading a
     * looser one there produced the MIRROR of the bug above: a definition
     * consumed from the output and registered nowhere.
     *
     * `> >``` ` is not a fence opener. The outer `> ` is a quote marker, and
     * what is left, `>``` `, carries no space after its `>` - so it is prose
     * inside the quote, which is exactly how the block parser renders it. The
     * tracker's looser rule counted TWO quote markers, found a fence, and held
     * the region open across `> > [r]: /u`, so that definition was skipped as
     * fenced content. The block parser meanwhile read it as a real nested
     * quote and emptied the line, so the document showed neither the
     * definition nor the link (markup-carve/carve-php#961).
     *
     * A real fence at that depth is `> > ``` `, which
     * {@see self::testRefDefInsideADoublyQuotedFenceIsNotCollected} covers -
     * the two must not both be fences.
     */
    public function testADefinitionUnderAQuotedNonFenceIsStillCollected(): void
    {
        $html = $this->converter->convert("> >```\n> > [r]: /u\n> >```\n\n[link][r]\n");

        $this->assertStringContainsString('<a href="/u">link</a>', $html);
    }

    public function testRefDefInsideADoublyQuotedFenceIsNotCollected(): void
    {
        $html = $this->converter->convert("> > ```\n> > [r]: /u\n> > ```\n\n[link][r]\n");

        $this->assertStringNotContainsString('href="/u"', $html);
    }

    public function testQuotedAttrLineDoesNotLeakToTopLevelDef(): void
    {
        // `> {.note}` is a (would-be) attribute block inside a
        // blockquote. The following top-level `[r]: /u` is outside the
        // blockquote — those attrs must NOT attach to it.
        $html = $this->converter->convert(
            "[x][r] here.\n\n> {.note}\n\n[r]: /u",
        );
        $this->assertStringContainsString('href="/u"', $html);
        $this->assertStringNotContainsString('class="note"', $html);
    }
}
