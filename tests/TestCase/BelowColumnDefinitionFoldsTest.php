<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A definition line below every content column is lazy text (PART 0 S4, strict
 * content-column rule): it opens nothing there, and with the item's paragraph
 * open it folds in - which is what this engine already did for a marker, a
 * heading, a quote and a table row since #717.
 *
 * A definition was the one kind still being CONSUMED: the collector pushed the
 * line trimmed, which put it at the item's own column 0 where the block parser
 * skips it as an already-extracted definition and renders nothing. The line
 * disappeared from the document entirely (carve-php#721) - the worse half of
 * any disagreement about where the text lands.
 *
 * MEASURED PER MEMBER, NOT PER CLASS (carve-php#1863). The claim here used to
 * be a blanket "Matches carve-rs and the executable spec", and it covered a
 * member the measurement does not reach. What holds:
 *
 *   - The twelve methods below assert eleven distinct documents. Ten of those
 *     eleven agree byte for byte across the executable spec, carve-js and
 *     carve-rs. carve-js was not named before and belongs in the list.
 *   - `testACommentPastTheContentColumnDoesNotCloseTheItem` does NOT. The
 *     executable spec, carve-js and carve-rs all end the item there and this
 *     engine alone keeps the paragraph open, so the bytes it pins are this
 *     engine's own answer and nothing else's. Filed as carve-php#1866; the
 *     expectation stays until that lands so the divergence is visible rather
 *     than untested.
 *
 * Measured against carve-js at 3eb0277, carve-rs at e6cac0d, and the executable
 * spec at the revision `tests/spec` is pinned to. A claim without a revision
 * beside it is a claim nobody can re-check.
 */
class BelowColumnDefinitionFoldsTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAFootnoteDefinitionOneColumnInFolds(): void
    {
        $html = $this->converter->convert("- - a\n [^f]: x");

        $this->assertStringContainsString("<li>a\n[^f]: x</li>", $html);
    }

    public function testALinkReferenceDefinitionOneColumnInFolds(): void
    {
        $html = $this->converter->convert("- - a\n [a]: /u");

        $this->assertStringContainsString("<li>a\n[a]: /u</li>", $html);
    }

    public function testAnAbbreviationDefinitionOneColumnInFolds(): void
    {
        $html = $this->converter->convert("- - a\n *[A]: x");

        $this->assertStringContainsString("<li>a\n*[A]: x</li>", $html);
    }

    public function testTheSameHoldsUnderAPlainLead(): void
    {
        $html = $this->converter->convert("- a\n [^f]: x");

        $this->assertStringContainsString("<li>a\n[^f]: x</li>", $html);
    }

    public function testAFoldedDefinitionRegistersNothing(): void
    {
        // It is text, so a reference to it elsewhere stays literal.
        $html = $this->converter->convert("- - a\n [^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('<p>see[^f]</p>', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    public function testADefinitionAtTheContentColumnIsNotFoldedAsText(): void
    {
        // The boundary this fix must not cross: AT the content column the line
        // is a definition, so it renders nothing rather than appearing as
        // literal text in the item.
        //
        // Whether it also REGISTERS was once a live split - carve-php and
        // carve-rs left it unregistered here while carve-js registered it. It
        // is not one any more: remeasured for carve-php#1863, no engine and not
        // the executable spec registers it, and all four render this document
        // identically.
        $html = $this->converter->convert("- - a\n\n  [^f]: x");

        $this->assertStringNotContainsString('[^f]: x', $html);
    }

    public function testATopLevelDefinitionIsUnaffected(): void
    {
        $html = $this->converter->convert("[^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('doc-noteref', $html);
    }

    public function testNothingIsLost(): void
    {
        // The regression this fixes is content LOSS, so assert the text is
        // present rather than only where it landed.
        foreach (['[^f]: x', '[a]: /u', '*[A]: x'] as $definition) {
            $html = $this->converter->convert("- - a\n " . $definition);

            $this->assertStringContainsString($definition, $html, $definition . ' vanished');
        }
    }

    public function testACommentIsNotFoldedButStaysInvisible(): void
    {
        // PART 9 §24 C3: a comment is the ONE construct recognized at any
        // column. Folding it would make `%% c` VISIBLE, which is the one
        // outcome a comment may never have. carve#618.
        $html = $this->converter->convert("- - a\n %% c");

        $this->assertStringNotContainsString('%% c', $html);
        $this->assertSame(
            "<ul>\n  <li>\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>",
            trim($html),
        );
    }

    public function testACommentDoesNotCloseTheItem(): void
    {
        // The other half of being invisible: a following line continues the
        // item's paragraph across the comment, byte for byte with carve-js and
        // the executable spec - CONFIRMED, and carve-rs agrees too. Column 1 is
        // BELOW the item's content column 2, which is the band where all four
        // readings answer the same way.
        $html = $this->converter->convert("- a\n %% c\nb");

        $this->assertSame("<ul>\n  <li>a\n    b\n  </li>\n</ul>", trim($html));
    }

    public function testACommentPastTheContentColumnDoesNotCloseTheItem(): void
    {
        // PINS A KNOWN DIVERGENCE (carve-php#1866). At column 3 the comment is
        // PAST the item's content column 2, so it sits at the item body's own
        // column 1 - a block position, where PART 9 §24 C3 ends the paragraph
        // under it and `tail` becomes a document paragraph. The executable
        // spec, carve-js and carve-rs all do that; this engine keeps the
        // paragraph open and folds `tail` in, and it does so at every column
        // that is not exactly a content column.
        //
        // The expectation below is therefore this engine's answer, NOT the
        // agreed one. Kept so the divergence stays measured instead of
        // untested; it moves with the fix.
        $html = $this->converter->convert("- a\n   %% c\ntail");

        $this->assertSame("<ul>\n  <li>a\n    tail\n  </li>\n</ul>", trim($html));
    }

    public function testALazyCommentDoesNotEraseTheInvisibleBlockBeforeIt(): void
    {
        $html = $this->converter->convert("- a\n  %% c\n %% d\n b");

        $this->assertSame("<ul>\n  <li>a\n    b\n  </li>\n</ul>", trim($html));
    }
}
