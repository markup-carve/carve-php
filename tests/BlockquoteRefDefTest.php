<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
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
}
