<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §24 C3's "at or past the deepest one" is the deepest column the LINE
 * REACHES, not the deepest container left open (markup-carve/carve#1896).
 *
 * `- - x` opens items at content columns 2 and 4. A block opener at column 3
 * reaches the OUTER item and registers against it; this engine folded it as
 * lazy paragraph text, so indenting a definition by one space stopped it
 * registering and one more brought it back. Past a container's content column
 * more indentation may change which container owns a line, never whether the
 * line is a definition at all.
 *
 * STATED OVER BLOCK OPENERS, NOT OVER DEFINITIONS. The same shape with a
 * heading or a thematic break diverged identically, so a definition-only rule
 * would leave the engine folding a heading it registers a definition over -
 * which is why the heading and thematic-break columns are pinned beside the
 * definition ones rather than assumed to follow.
 *
 * The controls are the other half. Column 1 reaches no content column at all
 * and stays item text at every depth, and a line carrying no `>` reaches no
 * column inside a quote whatever its own indentation says - both are the
 * boundary a fix written one column too wide crosses.
 */
class ABlockOpenerRegistersAgainstTheColumnItReachesTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * Every column `- - x` makes live, and the one below all of them.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function twoItemColumnProvider(): array
    {
        return [
            'the frame base' => [0, true],
            'below every column' => [1, false],
            'the outer content column' => [2, true],
            'between the two' => [3, true],
            'the inner content column' => [4, true],
            'past the inner column' => [5, true],
        ];
    }

    #[DataProvider('twoItemColumnProvider')]
    public function testADefinitionRegistersFromEveryColumnItReaches(int $column, bool $registers): void
    {
        $html = $this->converter->convert(
            "- - x\n" . str_repeat(' ', $column) . "[r]: /url\n\nSee [r][].\n",
        );

        if ($registers) {
            $this->assertStringContainsString('<a href="/url">r</a>', $html);
            $this->assertStringNotContainsString('[r]: /url', $html);

            return;
        }

        $this->assertStringContainsString('[r]: /url', $html);
        $this->assertStringNotContainsString('<a href="/url">r</a>', $html);
    }

    #[DataProvider('twoItemColumnProvider')]
    public function testAHeadingOpensFromEveryColumnItReaches(int $column, bool $opens): void
    {
        $html = $this->converter->convert("- - x\n" . str_repeat(' ', $column) . "# H\n");

        if ($opens) {
            $this->assertStringContainsString('<h1', $html);

            return;
        }

        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringContainsString('# H', $html);
    }

    #[DataProvider('twoItemColumnProvider')]
    public function testAThematicBreakOpensFromEveryColumnItReaches(int $column, bool $opens): void
    {
        $html = $this->converter->convert("- - x\n" . str_repeat(' ', $column) . "---\n");

        if ($opens) {
            $this->assertStringContainsString('<hr', $html);

            return;
        }

        // Folded into the item's paragraph the run is inline text, and
        // typography rewrites it there - so what is asserted is that no break
        // opened, not the bytes the fold leaves behind.
        $this->assertStringNotContainsString('<hr', $html);
    }

    /**
     * One level deeper, where three columns are live at once.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function threeItemColumnProvider(): array
    {
        return [
            'the frame base' => [0, true],
            'below every column' => [1, false],
            'the outermost content column' => [2, true],
            'between the first two' => [3, true],
            'the middle content column' => [4, true],
            'between the last two' => [5, true],
            'the innermost content column' => [6, true],
            'past the innermost column' => [7, true],
        ];
    }

    #[DataProvider('threeItemColumnProvider')]
    public function testTheDepthDoesNotChangeTheRule(int $column, bool $registers): void
    {
        $html = $this->converter->convert(
            "- - - x\n" . str_repeat(' ', $column) . "[r]: /url\n\nSee [r][].\n",
        );

        if ($registers) {
            $this->assertStringContainsString('<a href="/url">r</a>', $html);

            return;
        }

        $this->assertStringContainsString('[r]: /url', $html);
        $this->assertStringNotContainsString('<a href="/url">r</a>', $html);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function quoteColumnProvider(): array
    {
        return [
            'below the quote column' => [1],
            'the quote content column' => [2],
            'the outer item column' => [3],
            'between the two items' => [4],
            'the inner item column' => [5],
            'past every column' => [6],
        ];
    }

    #[DataProvider('quoteColumnProvider')]
    public function testAnUnmarkedQuoteLineReachesNoColumn(int $column): void
    {
        // A LAZY QUOTE LINE CARRIES NO `>`, so it extends an open paragraph and
        // nothing else. The quote hands it down unstripped, which is the only
        // reason its own indentation looks like it reaches an item below it -
        // reading that indentation as a column opened blocks inside the item
        // that every other engine folds.
        $html = $this->converter->convert(
            "> - - x\n" . str_repeat(' ', $column) . "[r]: /url\n\nSee [r][].\n",
        );

        $this->assertStringContainsString('[r]: /url', $html);
        $this->assertStringNotContainsString('<a href="/url">r</a>', $html);
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function markedQuoteColumnProvider(): array
    {
        return [
            'the quote frame base' => [0, true],
            'below every item column' => [1, false],
            'the outer item column' => [2, true],
            'between the two items' => [3, true],
            'the inner item column' => [4, true],
        ];
    }

    #[DataProvider('markedQuoteColumnProvider')]
    public function testTheSameQuoteLineCarryingItsMarkerDoesReach(int $column, bool $registers): void
    {
        $html = $this->converter->convert(
            "> - - x\n>" . str_repeat(' ', $column + 1) . "[r]: /url\n\nSee [r][].\n",
        );

        if ($registers) {
            $this->assertStringContainsString('<a href="/url">r</a>', $html);

            return;
        }

        $this->assertStringContainsString('[r]: /url', $html);
        $this->assertStringNotContainsString('<a href="/url">r</a>', $html);
    }
}
