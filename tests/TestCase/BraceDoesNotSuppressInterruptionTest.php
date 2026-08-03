<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An unclosed `{` does not suppress paragraph interruption.
 *
 * `tryParseParagraph` carried a brace-depth counter and skipped the
 * interrupts-paragraph check while the depth was above zero, so an unclosed
 * brace turned every following line into paragraph text until a blank line.
 *
 * The worst of that is a COMMENT. `%%` and `%%%` exist to hold content the
 * author does not want published, and after an unclosed `{` this engine
 * published it.
 *
 * The rule was carve-php's alone - carve-js and carve-rs interrupt normally
 * after `text{a=x`, which is the very example the removed comment used - and
 * PART 9 §10's I1 says nothing about brace state. Nor does it protect anything:
 * an inline attribute block cannot span lines in any engine (`text{a=x` /
 * `y}` is literal text in all three).
 */
class BraceDoesNotSuppressInterruptionTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testACommentAfterAnUnclosedBraceIsNotPublished(): void
    {
        $html = $this->converter->convert("{\n%% internal note: do not ship\n");

        $this->assertStringNotContainsString('internal note', $html);
        $this->assertSame("<p>{</p>\n", $html);
    }

    public function testACommentFenceAfterAnUnclosedBraceIsNotPublished(): void
    {
        $html = $this->converter->convert("{\n%%%\nhidden block\n%%%\nafter\n");

        $this->assertStringNotContainsString('hidden block', $html);
        $this->assertStringContainsString('after', $html);
    }

    public function testAHeadingAfterAnUnclosedBraceStillInterrupts(): void
    {
        $html = $this->converter->convert("{\n# H\n");

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringNotContainsString('{ # H', $html);
    }

    public function testTheExampleTheRemovedRuleWasWrittenFor(): void
    {
        // `text{a=x` then `# H`. carve-js and carve-rs both interrupt.
        $html = $this->converter->convert("text{a=x\n# H\n");

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('<p>text{a=x</p>', $html);
    }

    public function testAnInlineAttributeBlockStillCannotSpanLines(): void
    {
        // Nothing is lost by dropping the suppression: the construct it was
        // protecting does not exist in any engine.
        $this->assertSame(
            "<p>text{a=x\ny}</p>\n",
            $this->converter->convert("text{a=x\ny}\n"),
        );
    }

    public function testOrdinaryParagraphContinuationIsUnaffected(): void
    {
        $this->assertSame(
            "<p>one\ntwo</p>\n",
            $this->converter->convert("one\ntwo\n"),
        );
    }
}
