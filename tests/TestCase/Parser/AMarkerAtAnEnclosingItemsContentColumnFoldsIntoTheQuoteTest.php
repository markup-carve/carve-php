<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unmarked marker at an ENCLOSING item's content column, with a quote below
 * it holding the innermost open paragraph, FOLDS into that paragraph.
 *
 * markup-carve/carve#1905, ported as carve-php#1882. `resources/spec/01-layout.ebnf:330`
 * is NORMATIVE: A QUOTE IS REACHED BY ITS MARKER, AND A COLUMN NEVER REACHES
 * INTO ONE. A line writing no `>` is in no quote whatever column it lands on,
 * so `CARVE-P0-020` does not govern - the only route by which the line touches
 * the quote is PART 0's lazy fold, and that makes it text in the quote's open
 * paragraph. This engine opened a sibling item inside the quote instead, an
 * answer neither clause supports.
 *
 * The paragraph twin already folded at this exact column, so the ruling makes
 * the marker twin agree with it rather than creating a new exception.
 *
 * THE COST, and it is the accepted one: a new list element after a quote needs
 * a blank line. After a heading, a fence, a paragraph or another item it still
 * does not - `noQuoteControlsProvider()` is those four, and they must not
 * move. Once inside a quote's lazy run, intervening prose does not escape it
 * either; a blank line is the only exit.
 *
 * Measured against the executable spec on markup-carve/carve#1922 at
 * `665112b3`, whose corpus section 448 is these nine documents - `main`
 * (`2f654da9`) does not carry them yet, so the pinned `tests/spec`
 * (`95fc3a04`) neither passes nor fails them. carve-js `4627270e` fails the
 * same three rows this fixes and is therefore NOT an oracle for them; it
 * agrees on all six others. Not measured against carve-rs: the published
 * `0.1.4` artifact is short of this rule family and is not a current oracle.
 */
class AMarkerAtAnEnclosingItemsContentColumnFoldsIntoTheQuoteTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    protected function html(string $source): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $this->converter->convert($source)));
    }

    /**
     * The three shapes the ruling moves. Each writes the marker at the
     * ENCLOSING item's content column - below the quote's own - so it reaches
     * no quote and folds.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function foldingProvider(): array
    {
        return [
            'a quoted list below the item lead' => [
                "- a\n  > - x\n  - m\n",
                '<ul> <li>a <blockquote> <ul> <li>x - m</li> </ul> </blockquote> </li> </ul>',
            ],
            'the quote on the lead line itself' => [
                "- > - x\n  - m\n",
                '<ul> <li> <blockquote> <ul> <li>x - m</li> </ul> </blockquote> </li> </ul>',
            ],
            'intervening prose does not escape the quote' => [
                "- a\n  > - x\n  p\n  - m\n",
                '<ul> <li>a <blockquote> <ul> <li>x p - m</li> </ul> </blockquote> </li> </ul>',
            ],
        ];
    }

    #[DataProvider('foldingProvider')]
    public function testTheMarkerFolds(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * THE PARAGRAPH TWIN, which already folded before this ruling and is the
     * reason the ruling is a convergence rather than a new rule. If this row
     * ever moves, the fold has been rewritten rather than extended.
     */
    public function testTheParagraphTwinStillFolds(): void
    {
        $this->assertSame(
            '<ul> <li>a <blockquote><p>q - m</p></blockquote> </li> </ul>',
            $this->html("- a\n  > q\n  - m\n"),
        );
    }

    /**
     * THE BLANK-LINE ESCAPE, the one way out. It must keep opening an item, or
     * a quote inside a list would swallow everything written under it.
     */
    public function testABlankLineStillOpensAnItem(): void
    {
        $this->assertSame(
            '<ul> <li>a <blockquote> <ul> <li>x</li> </ul> </blockquote> <ul> <li>m</li> </ul> </li> </ul>',
            $this->html("- a\n  > - x\n\n  - m\n"),
        );
    }

    /**
     * A CLOSED BLOCK AFTER THE QUOTE STILL ENDS THE RUN. The ruling holds the
     * fold open across PROSE; a heading or a thematic break at the item's
     * content column returns from its own branch with the paragraph closed, so
     * the marker below it opens an element as before. carve-js `4627270e`
     * answers both alike. These two are what say the re-arm did not simply
     * pin `quoteParagraph` true for the rest of the item.
     *
     * @return array<string, array{0: string}>
     */
    public static function closedBlockAfterTheQuoteProvider(): array
    {
        return [
            'a lazy heading' => ["- a\n  > q\n  # h\n  - m\n"],
            'a lazy thematic break' => ["- a\n  > q\n  ---\n  - m\n"],
        ];
    }

    #[DataProvider('closedBlockAfterTheQuoteProvider')]
    public function testAClosedBlockAfterTheQuoteEndsTheRun(string $source): void
    {
        $this->assertStringContainsString('<li>m</li>', $this->html($source));
    }

    /**
     * THE NO-QUOTE CONTROLS. Carve's "no blank line needed before a new list
     * element" property is unchanged everywhere a quote is not involved, and
     * these four are what say so: after another item, a heading, a fence or a
     * paragraph the marker still opens an element. `quoteParagraph` is false
     * throughout all four, so a change that moved one of them would be reaching
     * far outside the ruling.
     *
     * @return array<string, array{0: string}>
     */
    public static function noQuoteControlsProvider(): array
    {
        return [
            'after another item' => ["- a\n  - b\n  - m\n"],
            'after a heading' => ["- a\n  # h\n  - m\n"],
            'after a fence' => ["- a\n  ```\n  c\n  ```\n  - m\n"],
            'after a paragraph' => ["- a\n  p\n  - m\n"],
        ];
    }

    #[DataProvider('noQuoteControlsProvider')]
    public function testANoQuoteShapeStillOpensAnItem(string $source): void
    {
        $this->assertStringContainsString('<li>m</li>', $this->html($source));
    }
}
