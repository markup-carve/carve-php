<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\BbcodeToCarve;
use PHPUnit\Framework\TestCase;

/**
 * The BBCode code fence keeps its trim (markup-carve/carve-php#1612).
 *
 * `[code]` content is hidden behind a sentinel for the length of the pipeline
 * (markup-carve/carve-php#1206), and `convertCode()` fenced the trimmed body -
 * but by the time it ran, that body was the KEY, which has no whitespace to
 * trim. The newlines the trim used to remove sat inside the stash and came back
 * with the content, so every fenced block gained a blank line above and below
 * its code.
 *
 * A forum post's `[code]` almost always carries a newline right after the
 * opening tag, so this fired on ordinary input rather than on a crafted case.
 *
 * The trim moved to STASH time, which is where the body still is the author's
 * text. It is the block family only: the inline family is written verbatim
 * between backticks and has never been trimmed.
 *
 * carve-js hit the mirror image of this and moved its trim the same way
 * (markup-carve/carve-js#1375), where a test pinning the trimmed form went red
 * the moment the content moved behind the key. Nothing here pinned it, which is
 * why it went unnoticed.
 */
class BbcodeCodeFenceKeepsItsTrimTest extends TestCase
{
    private BbcodeToCarve $converter;

    protected function setUp(): void
    {
        $this->converter = new BbcodeToCarve();
    }

    /**
     * THE DEFECT, in the shape an ordinary forum post carries: a newline right
     * after the opening tag and another before the closing one.
     */
    public function testAFencedBlockWithLanguageDropsItsEdgeNewlines(): void
    {
        $this->assertSame(
            "```php\nX = 1;\n```\n",
            $this->converter->convert("[code=php]\nX = 1;\n[/code]"),
        );
    }

    /**
     * THE DEFECT, without a language, and with spaces as well as newlines - the
     * trim takes both, as it did before the content moved behind the key.
     */
    public function testAFencedBlockWithoutLanguageDropsItsEdgeWhitespace(): void
    {
        $this->assertSame(
            "```\na\n```\n",
            $this->converter->convert("[code]\n a \n[/code]"),
        );
    }

    /**
     * Several lines: only the OUTER edges go. The blank line between two lines
     * of code is the code's own content and has to survive, or the trim has
     * been replaced with something greedier.
     */
    public function testAnInteriorBlankLineSurvives(): void
    {
        $this->assertSame(
            "```\na\n\nb\n```\n",
            $this->converter->convert("[code]\na\n\nb\n[/code]"),
        );
    }

    /**
     * Interior indentation is content too. A trim that reached inside would
     * flatten the one thing a code block exists to preserve.
     */
    public function testInteriorIndentationSurvives(): void
    {
        $this->assertSame(
            "```\nif (x) {\n    y();\n}\n```\n",
            $this->converter->convert("[code]\nif (x) {\n    y();\n}\n[/code]"),
        );
    }

    /**
     * BOUND, and the reason the trim is per-family rather than global: the
     * inline runs are written verbatim between backticks and have never been
     * trimmed. Trimming the whole stash moves these two rows.
     */
    public function testTheInlineFamilyIsStillNotTrimmed(): void
    {
        $this->assertSame("` a `\n", $this->converter->convert('[c] a [/c]'));
        $this->assertSame("` b `\n", $this->converter->convert('[icode] b [/icode]'));
    }

    /**
     * BOUND: a body that needs no trim is unchanged, which is every case the
     * existing suite covered - and why nothing went red here.
     */
    public function testABodyWithNoEdgeWhitespaceIsUnchanged(): void
    {
        $this->assertSame("```php\necho 1;\n```\n", $this->converter->convert('[code=php]echo 1;[/code]'));
        $this->assertSame("```\na *b* c\n```\n", $this->converter->convert('[code]a *b* c[/code]'));
    }

    /**
     * BOUND: the content is still literal. The trim moving must not re-expose
     * the body to the passes below the escaper (markup-carve/carve-php#1206).
     */
    public function testEnclosedBbcodeIsStillLiteral(): void
    {
        $this->assertSame(
            "```\n[b]not bold[/b]\n```\n",
            $this->converter->convert("[code]\n[b]not bold[/b]\n[/code]"),
        );
    }

    /**
     * BOUND: the raw-HTML guard still holds. `[code= =html]` must not mint a
     * Carve `=html` raw block, and the language is still read out of the
     * opening tag, which is what the tags-stay-visible half of the stash
     * protects.
     */
    public function testTheRawHtmlGuardStillHolds(): void
    {
        $this->assertSame(
            "```html\n<b>x</b>\n```\n",
            $this->converter->convert("[code= =html]\n<b>x</b>\n[/code]"),
        );
    }

    /**
     * A body that is nothing BUT whitespace trims to empty, so the fence is
     * empty rather than holding a blank line. Stated because it is the one
     * input where the trim removes everything.
     */
    public function testAWhitespaceOnlyBodyLeavesAnEmptyFence(): void
    {
        $this->assertSame("```\n\n```\n", $this->converter->convert("[code]\n \n[/code]"));
    }
}
