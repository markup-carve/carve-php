<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §10g (markup-carve/carve#1122): the canonical writer emits the
 * AUTHORED form - the attribute line where attributes exist, the bare
 * `::: figure` opener, the children with one blank line between them, the
 * closer at the opener's width, and the group caption as a `^ ` line after
 * the closer with its `#` placeholder written back.
 *
 * The caret after a group closer is a CAPTION POSITION: the writer must not
 * escape the group caption's own caret, and it MUST keep the `\^ ` escape on
 * a paragraph that merely sits where the allowance would reach the closer.
 */
class TheWriterSpellsACompositeFigureAsAuthoredTest extends TestCase
{
    public function testTheAuthoredFormComesBackVerbatim(): void
    {
        $source = "{#fig-x .columns-2}\n::: figure\n{#fig-x-a}\n![one](a.png)\n^ (a) One\n\n{#fig-x-b}\n![two](b.png)\n^ (b) Two\n:::\n^ Figure #: Group caption\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    public function testTheGroupCaptionCaretIsNotEscaped(): void
    {
        $fmt = CarveConverter::toCarve("::: figure\n![one](a.png)\n^ (a) One\n:::\n^ Figure #: G\n");

        $this->assertStringContainsString("\n^ Figure #: G", $fmt);
        $this->assertStringNotContainsString('\\^ Figure', $fmt);
    }

    public function testADetachedCaretParagraphKeepsItsEscape(): void
    {
        // Corpus 318-composite-figures-6: fmt normalizes the blank-line run,
        // so without the escape the paragraph would re-parse as the group
        // caption and `parse(fmt(x)) == parse(x)` would break.
        $source = "::: figure\n![one](a.png)\n^ (a) One\n:::\n\n\n^ Figure #: Detached\n";
        $fmt = CarveConverter::toCarve($source);

        $this->assertStringContainsString('\\^ Figure #: Detached', $fmt);
        $converter = new CarveConverter();
        $this->assertSame($converter->convert($source), $converter->convert($fmt));
    }

    public function testTheInnerFenceWidensInward(): void
    {
        // A nested generic container inside the group takes the wider fence,
        // the discipline PART 9 §12 already sets for containers.
        $source = "::: figure\n:::: figure\n![one](a.png)\n^ (a) One\n::::\n:::\n^ Figure #: Outer only\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    public function testFmtIsIdempotentOnACompositeFigure(): void
    {
        $source = "{#g}\n::: figure\nProse.\n\n| a |\n|---|\n\n![one](a.png)\n^ (a) One\n:::\n^ Figure #: Mixed\n";
        $once = CarveConverter::toCarve($source);

        $this->assertSame($once, CarveConverter::toCarve($once));
    }
}
