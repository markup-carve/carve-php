<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A floating attribute belongs to the container it was written in.
 *
 * PART 9 §15 A2a floats a pending attribute forward to the next VISIBLE block
 * and A4 DROPS a run that reaches the end with nothing to attach to. A
 * container's boundary is such an end, so an attribute written inside one and
 * left with no block there attributes nothing - it does not reach the block
 * after the container.
 *
 * The pending state is parser-global, so every container has to end the run
 * itself, and the rule was added one container at a time: a div in carve#1028,
 * a list item in carve-php#757. The QUOTE and the DEFINITION DESCRIPTION never
 * got it, which is what corpus 329 caught.
 *
 * TWO ROWS ARE HERE RATHER THAN IN THE CORPUS. Each was a mutation that
 * survived - the code could be removed with the whole suite still green - and
 * carve-rs renders both as asserted:
 *
 *  - the quote's scope, which no corpus document reaches without ALSO reaching
 *    the lazy-continuation rule that hides it;
 *  - the "ends with" in `contentEndsWithAttributeBlock()`, which is about the
 *    last CONSTRUCT and not the last attribute seen.
 */
class AFloatingAttributeIsScopedToItsContainerTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * THE FIRST SURVIVING-MUTANT ROW. The attribute has a block to attach to
     * INSIDE the quote, so the lazy-continuation rule never fires and only the
     * scope decides. Without it the run escaped and attributed `tail`.
     */
    public function testAnAttributeWithATargetInsideTheQuoteDoesNotEscape(): void
    {
        $html = $this->html("> q\n> {.k}\n> more\n\ntail\n");

        $this->assertStringContainsString('<p class="k">more</p>', $html, $html);
        $this->assertStringContainsString('<p>tail</p>', $html, $html);
    }

    /**
     * The corpus row's shape, restated: an attribute with NO target in the
     * quote attributes nothing at all.
     */
    public function testAnAttributeWithNoTargetInTheQuoteAttributesNothing(): void
    {
        $html = $this->html("> q\n> {.k}\n\ntail\n");

        $this->assertStringContainsString('<p>tail</p>', $html, $html);
        $this->assertStringNotContainsString('class="k"', $html, $html);
    }

    /**
     * The same for a definition description.
     */
    public function testAnAttributeInADescriptionDoesNotEscapeIt(): void
    {
        $html = $this->html(":: t\n:  d\n   {.k}\ntail\n");

        $this->assertStringContainsString('<p>tail</p>', $html, $html);
        $this->assertStringNotContainsString('class="k"', $html, $html);
    }

    /**
     * A WRAPPED ATTRIBUTE BLOCK IS ONE TOO. §15 does not distinguish an
     * attribute block written on one line from one broken across several -
     * `attr_separator` admits a line break between attributes - and the
     * one-line predicate says no to `{.k` and no to `#x}`, so the whole form
     * used to fold into the paragraph and render its own source.
     */
    public function testAWrappedAttributeBlockInterruptsAParagraph(): void
    {
        $html = $this->html("d\n{.k\n#x}\ntail\n");

        $this->assertSame("<p>d</p>\n<p class=\"k\" id=\"x\">tail</p>\n", $html);
    }

    /**
     * INDENTED QUOTE CONTENT IS NOT AN ATTRIBUTE LINE. A quoted line carrying
     * one extra space before the brace puts that indentation INSIDE the quote,
     * and the attribute parser requires the
     * line to BEGIN with `{`, so it is ordinary paragraph text and a flush-left
     * line lazily continues it. Read ltrimmed, the tracker closed a paragraph
     * the parser had built - the same class of disagreement carve-php#969
     * removed from the quote-marker walk (raised by codex review).
     */
    public function testIndentedQuoteContentIsNotAnAttributeLine(): void
    {
        $html = $this->html(">  {.k}\ntail\n");

        $this->assertSame("<blockquote><p>{.k}\ntail</p></blockquote>\n", $html);
    }

    /**
     * A WRAPPED BLOCK IS AN ATTRIBUTE INSIDE A QUOTE TOO, which is the row that
     * separates "the top level only" from "wherever it is written". §15 carves
     * out no container.
     */
    public function testAWrappedAttributeInAQuoteAttributesTheBlockAfterIt(): void
    {
        $html = $this->html("> q\n> {.k\n> #x}\ntail\n");

        $this->assertStringContainsString('<p class="k" id="x">tail</p>', $html, $html);
        $this->assertStringNotContainsString('{.k', $html, $html);
    }

    /**
     * THE SECOND SURVIVING-MUTANT ROW. "Ends with an attribute block" is about
     * the last CONSTRUCT, not the last attribute seen: text written under the
     * attribute is a paragraph, and a flush-left line folds into it. A sticky
     * flag ended the description here instead.
     *
     * The flag is carried on the tracker cursor rather than recomputed, so this
     * row also pins that the INCREMENTAL form still forgets an attribute once a
     * visible line follows it.
     */
    public function testTextUnderAWrappedAttributeReopensTheParagraph(): void
    {
        $html = $this->html(":: t\n:  d\n   {.k\n   #x}\n   text\ntail\n");

        $this->assertStringContainsString("text\ntail", $html, $html);
    }
}
