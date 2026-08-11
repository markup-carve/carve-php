<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * `::: |` is the line-block opener, and the pipe IS the whole info string. It
 * takes no quoted header and no `[label]`.
 *
 * The colon-fence opener let ANY type token carry a header and a label, and `|`
 * went through that branch like a word would. So `::: | [id]` opened a line block
 * and `::: | "t"` opened a div whose class was the literal `| "t"`, where the
 * executable spec, carve-js and carve-rs all read both as ordinary paragraph text
 * (carve-php#820).
 *
 * The consequences compounded: with the line block open, the rest of the document
 * was swallowed into it - a footnote definition inside rendered as visible text
 * instead of registering, and the closing `:::` the others read as an empty div
 * opener was eaten as the line block's closer.
 *
 * Nothing in the grammar gives a line block a label or a title.
 */
class LineBlockOpenerIsTheBarePipeTest extends TestCase
{
    protected function html(string $source): string
    {
        return (string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source));
    }

    public function testABarePipeStillOpensALineBlock(): void
    {
        // The shape that must keep working.
        $html = $this->html("::: |\na\n:::\n");

        $this->assertStringContainsString('class="line-block"', $html);
    }

    public function testAPipeWithABracketedLabelIsParagraphText(): void
    {
        $html = $this->html("::: | [id]\n[^f]: note\n:::\n");

        $this->assertStringNotContainsString('line-block', $html);
        $this->assertStringContainsString('<p>::: | [id]</p>', $html);
    }

    public function testTheFootnoteDefinitionAfterItStillRegisters(): void
    {
        // The compounding failure: swallowed into the line block, the definition
        // rendered as visible text instead of registering.
        $html = $this->html("::: | [id]\n[^f]: note\n:::\n\nsee[^f]\n");

        $this->assertStringContainsString('doc-endnotes', $html);
        $this->assertStringNotContainsString('[^f]: note', $html);
    }

    public function testAPipeWithAQuotedHeaderIsParagraphText(): void
    {
        // This one produced a div with a literal `| "t"` class, which is worse
        // than the wrong block type - it emitted a class no author wrote.
        $html = $this->html("::: | \"t\"\na\n:::\n");

        $this->assertStringNotContainsString('line-block', $html);
        $this->assertStringNotContainsString('class="|', $html);
    }

    public function testAPipeWithTrailingTextWasAlreadyParagraphText(): void
    {
        // Unchanged by the fix, and the shape that shows the rule was already
        // half-right: plain text after the pipe never opened a line block.
        $html = $this->html("::: | x\na\n:::\n");

        $this->assertStringNotContainsString('line-block', $html);
    }

    public function testAWORDTypeStillTakesItsLabel(): void
    {
        // The boundary the fix must not move: a real type token keeps the
        // header/label grammar. Only the pipe is restricted.
        $html = $this->html("::: note [id]\na\n:::\n");

        $this->assertStringContainsString('admonition note', $html);
        $this->assertStringContainsString('div-label', $html);
    }

    public function testATypelessBareLabelStillOpensAGenericDiv(): void
    {
        // The other branch that handles labels, untouched.
        $html = $this->html("::: [id]\na\n:::\n");

        $this->assertStringContainsString('div-label', $html);
    }
}
