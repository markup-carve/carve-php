<?php

declare(strict_types=1);

namespace Carve\Test;

use Carve\CarveConverter;
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

    public function testListItemRefDefIsNotCollectedGlobally(): void
    {
        // List markers are deliberately NOT stripped (avoids the
        // ambiguous "is `- [r]: /u` a list item or a definition?" trap).
        // `[x][r]` stays unresolved when the only "definition" lives
        // inside a list item.
        $html = $this->converter->convert("[x][r] here.\n\n- [r]: /u");
        $this->assertStringNotContainsString('href="/u"', $html);
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

    public function testMultiLineBlockquoteDefResolves(): void
    {
        // The URL on a continuation line, also inside the blockquote.
        $html = $this->converter->convert(
            "[x][r]\n\n> [r]:\n>   /u",
        );
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testTwoDefsInSameBlockquoteResolve(): void
    {
        $src = "[a][one] and [b][two]\n\n> [one]: /1\n> [two]: /2";
        $html = $this->converter->convert($src);
        $this->assertStringContainsString('<a href="/1">a</a>', $html);
        $this->assertStringContainsString('<a href="/2">b</a>', $html);
    }

    public function testRefDefInsideNoSpaceNestedBlockquoteIsCollected(): void
    {
        // The space after `>` is optional, so `>> [r]: /u` IS a nested block
        // quote and the reference definition inside it is collected (matching
        // carve-js). Previously php required `> ` and left it unresolved.
        $html = $this->converter->convert("[x][r]\n\n>> [r]: /u");
        $this->assertStringContainsString('href="/u"', $html);
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

    public function testUnquotedDefAfterQuotedProseFoldsAndIsNotRegistered(): void
    {
        // One-rule §10: an unquoted `[r]: /u` after an OPEN quoted paragraph
        // lazily continues the quote as literal text -- it must NOT be
        // registered, so a later `[x][r]` does not resolve. Depth need not match
        // the open paragraph's (carve folds into ANY open paragraph).
        $html = $this->converter->convert("> quote\n[r]: /u\n\nSee [x][r].\n");
        $this->assertStringNotContainsString('href="/u"', $html);
        $this->assertStringContainsString('[r]: /u', $html);
    }

    public function testQuotedDefAfterTopLevelProseFoldsAndIsNotRegistered(): void
    {
        // A deeper `> [r]: /u` line after open top-level prose folds into the
        // paragraph (the `>` opener does not interrupt), so the definition is
        // literal text and not registered.
        $html = $this->converter->convert("text\n> [r]: /u\n\nSee [x][r].\n");
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    public function testInvalidAttrLineBeforeDefDoesNotBlockFolding(): void
    {
        // `{.123}` is an INVALID attribute payload, so the real parser keeps it
        // as paragraph text; the following `[r]: /u` then folds into that
        // paragraph and is not registered. The prepass must agree (it must not
        // treat the invalid line as an invisible construct opening a boundary).
        $html = $this->converter->convert("{.123}\n[r]: /u\n\nSee [x][r].\n");
        $this->assertStringNotContainsString('href="/u"', $html);
        $this->assertStringContainsString('[r]: /u', $html);
    }

    public function testDefAfterClosedDivIsRegistered(): void
    {
        // A `:::` div with content closes at its `:::`, so the following
        // `[r]: /u` sits at a block boundary and IS registered. The prepass must
        // track the div closer to reset its open-paragraph state, else the def
        // would be wrongly skipped and the link lost.
        $html = $this->converter->convert(":::\ntext\n:::\n[r]: /u\n\nSee [x][r].\n");
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testDefAfterMultiLineAttrBlockIsRegistered(): void
    {
        // A valid multi-line `{...}` attribute block floats to the next block;
        // the `[r]: /u` after it is a real definition and IS registered.
        $html = $this->converter->convert("{.note\n  #id}\n[r]: /u\n\nSee [x][r].\n");
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testDefAfterUnterminatedDivOpenerFoldsAndIsNotRegistered(): void
    {
        // An unterminated `:::` is ordinary paragraph text (no closer ahead), so
        // the following `[r]: /u` folds into that paragraph and is NOT
        // registered. The prepass must only treat a `:::` opener as a boundary
        // when a closer exists ahead, matching tryParseDiv.
        $html = $this->converter->convert(":::\n[r]: /u\n\nSee [x][r].\n");
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    public function testQuotedAttrBeforeQuotedDefStillResolves(): void
    {
        // A quoted single-line attribute line `> {.note}` before a quoted
        // `> [r]: /u` must not cause the def to be skipped: the prepass tests the
        // blockquote-stripped content, so `{.note}` is recognized as invisible
        // and the quoted definition is still collected (and its attrs apply).
        $html = $this->converter->convert("> {.note}\n> [r]: /u\n\nSee [x][r].\n");
        $this->assertStringContainsString('href="/u"', $html);
        $this->assertStringContainsString('class="note"', $html);
    }

    public function testIndentedCommentBeforeDefStillRegistersDef(): void
    {
        // A line comment is recognized after leading whitespace (as the block
        // parser does), so an indented `  %% note` at a boundary opens no
        // paragraph and the following `[r]: /u` is still registered.
        $html = $this->converter->convert("  %% note\n[r]: /u\n\nSee [x][r].\n");
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testDefAfterClosedQuotedDivIsRegistered(): void
    {
        // A div inside a blockquote (`> :::` … `> :::`) closes at its quoted
        // closer; the following top-level `[r]: /u` is at a boundary and IS
        // registered. The prepass strips quote markers in the closer lookahead.
        $html = $this->converter->convert(
            "> :::\n> text\n> :::\n[r]: /u\n\nSee [x][r].\n",
        );
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testDefAfterCaptionAndHeadingIsRegistered(): void
    {
        // A caption (`^ `) ends the open (image) paragraph; the heading after it
        // is a fresh block, so the `[r]: /u` is at a boundary and IS registered.
        $html = $this->converter->convert(
            "![a](img)\n^ cap\n# H\n[r]: /u\n\nSee [x][r].\n",
        );
        $this->assertStringContainsString('<a href="/u">x</a>', $html);
    }

    public function testDivCloserInsideCodeFenceDoesNotOpenDivBoundary(): void
    {
        // A `:::` opener whose only matching `:::` lives inside a fenced code
        // block has no real closer, so it is paragraph text; the `[r]: /u` after
        // it folds in and is NOT registered. The prepass closer lookahead skips
        // closers inside code fences (matching tryParseDiv).
        $html = $this->converter->convert(
            ":::\n```\n:::\n```\n[r]: /u\n\nSee [x][r].\n",
        );
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    public function testQuotedMultiLineAttrBeforeQuotedDefStillResolves(): void
    {
        // A quoted multi-line attribute block (`> {.note` … `>   #id}`) before a
        // quoted `> [r]: /u` is recognized as invisible (the prepass scans the
        // blockquote-stripped lines), so the quoted definition is still
        // collected and its attributes apply to the resolved link.
        $html = $this->converter->convert(
            "> {.note\n>   #id}\n> [r]: /u\n\nSee [x][r].\n",
        );
        $this->assertStringContainsString('href="/u"', $html);
        $this->assertStringContainsString('class="note"', $html);
    }
}
