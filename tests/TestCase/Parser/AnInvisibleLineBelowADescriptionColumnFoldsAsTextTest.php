<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Below a definition description's content column an invisible line is lazy
 * paragraph text OF THAT CONTAINER (markup-carve/carve#1809, §10 I5 DEFINITION
 * OWNERSHIP IS COLUMN-SCOPED; markup-carve/carve-php#1799, corpus 430 and 431).
 *
 * This build ejected it to DOCUMENT level - or, for the attribute kind, dropped
 * it entirely - while folding the identical line one host over in a list item.
 * §10 I5's missing half was WHICH container: "lazy paragraph text" names an
 * operation on an OPEN paragraph, so ending the description and emitting the
 * same characters one level out has not carried the sentence out.
 *
 * The fix is one argument on `lineOpensBlockForLooseness()`. The fold branch
 * already APPENDS the line to the previous body entry, which is the lazy frame
 * the ruling asks for - `parseBlocks()` reads an entry as one line, so nothing
 * inside one can be recognized as a block a second time. What kept the line out
 * of that branch was counting the invisible kinds as openers.
 *
 * The COMMENT keeps its arm: it is column-exempt (PART 9 §24) and renders
 * nothing at any column, which corpus 430-5 pins.
 */
class AnInvisibleLineBelowADescriptionColumnFoldsAsTextTest extends TestCase
{
    protected function html(string $source): string
    {
        return rtrim((new CarveConverter())->convert($source), "\n");
    }

    protected function folds(string $line): string
    {
        return "<dl>\n  <dt>t</dt>\n  <dd>d\n" . $line . "\ntail</dd>\n</dl>";
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bandKinds(): array
    {
        return [
            'link reference definition' => ['[r]: /u'],
            'footnote definition' => ['[^f]: n'],
            'attribute line' => ['{.k}'],
            'abbreviation definition' => ['*[A]: a'],
            'plain line (the control the others must match)' => ['x'],
        ];
    }

    #[DataProvider('bandKinds')]
    public function testEveryKindFoldsAtBothColumnsOfTheBand(string $line): void
    {
        // The band is two columns wide under a `:  ` body and the answer does
        // not move inside it.
        foreach ([' ', '  '] as $indent) {
            $this->assertSame(
                $this->folds($line),
                $this->html(":: t\n:  d\n" . $indent . $line . "\ntail\n"),
                'indent ' . strlen($indent),
            );
        }
    }

    /**
     * The half-fold rows: characters on the page AND an entry in a symbol table
     * is the shape a bytes-only assertion passes.
     */
    public function testNothingRegistersSoAReferenceBelowStaysLiteral(): void
    {
        $this->assertSame(
            $this->folds('[r]: /u') . "\n<p>See [text][r].</p>",
            $this->html(":: t\n:  d\n  [r]: /u\ntail\n\nSee [text][r].\n"),
        );
        $this->assertSame(
            $this->folds('[^f]: n') . "\n<p>See[^f]</p>",
            $this->html(":: t\n:  d\n  [^f]: n\ntail\n\nSee[^f]\n"),
        );
        $abbr = $this->html(":: t\n:  d\n  *[A]: a\ntail\n\nA here\n");
        $this->assertSame($this->folds('*[A]: a') . "\n<p>A here</p>", $abbr);
        $this->assertStringNotContainsString('<abbr', $abbr);
    }

    /**
     * The attribute kind failed differently from the definitions: it did not
     * reach the page at all. It was collected AS an attribute, closed the
     * paragraph it was written under, and §15 A4 discarded it - so the authored
     * characters reached neither the reader nor a block.
     */
    public function testTheAttributeAttachesToNothingInsideTheDescription(): void
    {
        $html = $this->html(":: t\n:  d\n  {.k}\ntail\n");

        $this->assertSame($this->folds('{.k}'), $html);
        $this->assertStringNotContainsString('class=', $html);
    }

    public function testAControlACommentIsColumnExemptAndRendersNothing(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>",
            $this->html(":: t\n:  d\n  %% c\n"),
        );
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>tail</p>",
            $this->html(":: t\n:  d\n  %% c\ntail\n"),
        );
    }

    /**
     * The half that must NOT move. The amended BELOW THE BODY'S COLUMN THE BODY
     * ENDS bullet is explicit that it is about OPENERS, so a fix that folded
     * everything below the column fails here.
     */
    public function testAControlARealOpenerBelowTheColumnStillEndsTheBody(): void
    {
        foreach ([' ', '  '] as $indent) {
            foreach (['> q', '# h', '| a |', '---', '::: note'] as $opener) {
                $html = $this->html(":: t\n:  d\n" . $indent . $opener . "\n");
                $this->assertStringStartsWith(
                    "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>",
                    $html,
                    $opener . ' at indent ' . strlen($indent) . ': ' . $html,
                );
            }
        }
    }

    /**
     * Corpus 431 and 431-4. Column 0 is the document's own opener column: the
     * description ends, a definition registers, a floating attribute attaches
     * forward.
     */
    public function testAControlAtColumnZeroTheLineActs(): void
    {
        $dd = "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n";
        $this->assertSame($dd . '<p class="k">tail</p>', $this->html(":: t\n:  d\n{.k}\ntail\n"));
        $this->assertSame(
            $dd . '<p>See <a href="/u">text</a>.</p>',
            $this->html(":: t\n:  d\n[r]: /u\n\nSee [text][r].\n"),
        );
    }

    /**
     * Not this band. A definition AT the column is collected, and an attribute
     * at the column is scoped to the description and dropped by §15 A4 - corpus
     * 329-a-floating-attribute-is-scoped-to-the-container-that-holds-it-5.
     */
    public function testAControlAtTheContentColumnTheLineIsInsideTheDescription(): void
    {
        $dd = "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n";
        $this->assertSame(
            $dd . "<p>tail</p>\n" . '<p>See <a href="/u">text</a>.</p>',
            $this->html(":: t\n:  d\n   [r]: /u\ntail\n\nSee [text][r].\n"),
        );
        $this->assertSame($dd . '<p>tail</p>', $this->html(":: t\n:  d\n   {.k}\ntail\n"));
    }

    /**
     * The host whose answer this is, in the same build. It was their
     * DISAGREEMENT that was the defect, so one host cannot record it.
     */
    public function testAControlTheListItemHostItAgreesWith(): void
    {
        $this->assertSame(
            "<ul>\n  <li>d\n[r]: /u\ntail</li>\n</ul>",
            $this->html("- d\n [r]: /u\ntail\n"),
        );
    }
}
