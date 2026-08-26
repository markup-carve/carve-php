<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An attribute line at COLUMN 0 under a definition description is a
 * document-level floating attribute and attaches to the block after it
 * (markup-carve/carve-php#1794, ruled on markup-carve/carve#1801).
 *
 * The `dl` ends at that line in every spelling, so by the time the next block is
 * classified the attribute sits at document level with a visible block after it,
 * which is §15 A2 FLOAT FORWARD. A2 says pending SURVIVES blank lines; it does
 * not say a blank line is what MAKES pending exist, so the blank-line spelling
 * of this very host cannot be the discriminator - and a list item and a block
 * quote both attach with no blank at all.
 *
 * WHY IT WAS LOST. The description body's flush-left continuation branch folded
 * the line INTO the body as lazy text. The tracker then read it on the NEXT
 * line, saw that an attribute block leaves no paragraph open and ended the body
 * one line too late - with the attribute already inside the description, where
 * `endContainerAttributeScope()` correctly discards it. So the characters were
 * consumed by the container that had already ended, and nothing was reported.
 * The line has to end the body BEFORE being collected.
 *
 * TWO NEIGHBOURING COLUMNS ARE CONTROLS AND MUST NOT MOVE:
 *
 *  - AT the description's content column the attribute is INSIDE the
 *    description, the description closes with nothing to attach to, and A4
 *    discards it. Pinned by corpus
 *    `329-a-floating-attribute-is-scoped-to-the-container-that-holds-it-5`.
 *  - BELOW the content column, at column 1 or 2, the line ends the body and
 *    stays LITERAL text at document level, which is what corpus
 *    `157-indented-attribute-line-stays-literal` asks for. carve-js and carve-rs
 *    agree on both.
 */
class AnAttributeLineAtColumnZeroUnderADescriptionAttachesTest extends TestCase
{
    protected function html(string $source): string
    {
        // `convert()` ends the document with a newline; the shapes here are
        // about the blocks, so it is trimmed rather than written into every row.
        return rtrim((new CarveConverter())->convert($source), "\n");
    }

    public function testItAttachesToTheParagraphAfterIt(): void
    {
        $html = $this->html(":: t\n:  d\n{.k}\ntail\n");

        $this->assertSame("<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p class=\"k\">tail</p>", $html);
    }

    /**
     * The blank-line spelling was already right, and it is what makes the row
     * above a defect rather than a choice: one document cannot depend on a blank
     * line A2 says is not the thing that creates pending.
     */
    public function testItAgreesWithTheBlankLineSpelling(): void
    {
        $this->assertSame(
            $this->html(":: t\n:  d\n\n{.k}\ntail\n"),
            $this->html(":: t\n:  d\n{.k}\ntail\n"),
        );
    }

    /**
     * A blank line AFTER the attribute is the same defect from the other side:
     * pending has to survive it, and it cannot survive being eaten by the
     * description first.
     */
    public function testPendingSurvivesABlankLineBelowTheAttribute(): void
    {
        $html = $this->html(":: t\n:  d\n{.k}\n\ntail\n");

        $this->assertStringContainsString('<p class="k">tail</p>', $html, $html);
    }

    /**
     * The WRAPPED form spans lines, so it is the arm a single-line predicate
     * cannot see. It also shows how far the fold used to reach: the description
     * swallowed the attribute AND `tail`, and attributed `tail` inside the `dd`.
     */
    public function testTheWrappedFormAttachesAtDocumentLevelToo(): void
    {
        $html = $this->html(":: t\n:  d\n{.k\n.j}\ntail\n");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p class=\"k j\">tail</p>",
            $html,
        );
    }

    /**
     * The next block may be another definition list, and then the attribute
     * belongs to THAT list rather than continuing this one. The fold used to
     * keep both entries in one `dl` and lose the class.
     */
    public function testItAttachesToAFollowingDefinitionList(): void
    {
        $html = $this->html(":: t\n:  d\n{.k}\n:: t2\n:  d2\n");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<dl class=\"k\">\n  <dt>t2</dt>\n  <dd>d2</dd>\n</dl>",
            $html,
        );
    }

    /**
     * A comment line at column 0 is NOT this rule and is deliberately left
     * alone: it is a separate divergence from carve-js and carve-rs and needs a
     * ruling of its own, so this row records what this build does rather than
     * asserting what it should do. Its only job here is to fail if the fix
     * reaches further than the attribute line.
     */
    public function testAControlCommentLineAtColumnZeroIsUntouched(): void
    {
        $html = $this->html(":: t\n:  d\n%% c\ntail\n");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <p>tail</p>\n  </dd>\n</dl>",
            $html,
        );
    }

    public function testAControlTheHostsThatAlreadyAttached(): void
    {
        $this->assertSame(
            "<ul>\n  <li>d</li>\n</ul>\n<p class=\"k\">tail</p>",
            $this->html("- d\n{.k}\ntail\n"),
        );
        $this->assertSame(
            "<blockquote><p>d</p></blockquote>\n<p class=\"k\">tail</p>",
            $this->html("> d\n{.k}\ntail\n"),
        );
    }

    /**
     * AT the content column the attribute is inside the description and is
     * dropped - corpus 329-…-5, which must keep passing.
     */
    public function testAControlAtTheContentColumnItIsStillDropped(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>tail</p>",
            $this->html(":: t\n:  d\n   {.k}\ntail\n"),
        );
    }

    /**
     * BELOW the content column it ends the body and stays literal - corpus
     * 157, and the same in carve-js and carve-rs.
     */
    public function testAControlBelowTheContentColumnItStaysLiteral(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>{.k}\ntail</p>",
            $this->html(":: t\n:  d\n  {.k}\ntail\n"),
        );
    }

    /**
     * A plain line at column 0 still FOLDS. The fix removes exactly one line
     * kind from the fold, and this is the row that says so.
     */
    public function testAControlAPlainLineAtColumnZeroStillFolds(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d\ntail</dd>\n</dl>",
            $this->html(":: t\n:  d\ntail\n"),
        );
    }
}
