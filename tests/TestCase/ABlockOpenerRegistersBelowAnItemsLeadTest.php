<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The column a block opener reaches is read on both routes into a nested item.
 *
 * PART 9 §24 C3's "at or past the deepest one" is the deepest column the LINE
 * REACHES (markup-carve/carve#1896). markup-carve/carve-php#1857 landed that
 * where the sub-list is the item's LEAD - `- - x` - because the marker-lead
 * collector records which entries it dedented, and the authored-base pass reads
 * that record to tell a line that reached a column from one that reached
 * nothing. A sub-list opened by a LATER line of the item is collected by the
 * nested-content loop instead, which recorded nothing, so the identical
 * document one column apart in the source answered differently.
 *
 * Both spellings put the opener at column 3, between the outer item's content
 * column 2 and the inner item's 4, so both reach the outer item.
 *
 * Every expectation here is measured against the executable spec at the
 * revision `tests/spec` is pinned to (`scripts/spec/layout.mjs` into
 * `scripts/spec/html.mjs`), not asserted from the engine's own output.
 */
class ABlockOpenerRegistersBelowAnItemsLeadTest extends TestCase
{
    private function html(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');
    }

    /**
     * The ticket's pair, one column apart in the source and identical in structure.
     */
    public function testADefinitionBelowTheLeadRegistersAgainstTheOuterItem(): void
    {
        $this->assertSame(
            '<ul> <li>a <ul> <li>x</li> </ul> </li> </ul> <p>See <a href="/url">r</a>.</p>',
            $this->html("- a\n  - x\n   [r]: /url\n\nSee [r][].\n"),
        );
    }

    public function testTheMarkerLeadSpellingStillAnswersTheSameWay(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li>x</li> </ul> </li> </ul> <p>See <a href="/url">r</a>.</p>',
            $this->html("- - x\n   [r]: /url\n\nSee [r][].\n"),
        );
    }

    /**
     * Stated over block openers, not over definitions: a rule scoped to
     * definitions would leave the engine folding a heading it registers a
     * definition over.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function openerKindProvider(): array
    {
        return [
            'heading' => [
                '# H',
                '<ul> <li>a <ul> <li>x</li> </ul> <h1 id="H">H</h1> </li> </ul>',
            ],
            'thematic break' => [
                '---',
                '<ul> <li>a <ul> <li>x</li> </ul> <hr> </li> </ul>',
            ],
            // An attribute block attaches to the block below it and there is
            // none, so it registers by rendering NOTHING. Folded as text it
            // rendered the braces and a tag span.
            'attribute block' => [
                '{#i}',
                '<ul> <li>a <ul> <li>x</li> </ul> </li> </ul>',
            ],
        ];
    }

    #[DataProvider('openerKindProvider')]
    public function testEveryBlockOpenerKindRegistersFromTheBetweenColumn(string $opener, string $expected): void
    {
        $this->assertSame($expected, $this->html("- a\n  - x\n   " . $opener . "\n"));
    }

    /**
     * The inner container's own kind does not enter into it: whatever the
     * sub-list's item opens, the opener below still reaches only the outer
     * item's column and registers there.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function innerPrefixProvider(): array
    {
        return [
            'two markers' => [
                '- - x',
                '<ul> <li>a <ul> <li> <ul> <li>x</li> </ul> </li> </ul> <h1 id="H">H</h1> </li> </ul>',
            ],
            'a marker over a quote' => [
                '- > x',
                '<ul> <li>a <ul> <li> <blockquote><p>x</p></blockquote> </li> </ul> <h1 id="H">H</h1> </li> </ul>',
            ],
            'a marker over two quotes' => [
                '- > > x',
                '<ul> <li>a <ul> <li> <blockquote> <blockquote><p>x</p></blockquote> </blockquote> </li> </ul>'
                    . ' <h1 id="H">H</h1> </li> </ul>',
            ],
            'a marker over a quote over a marker' => [
                '- > - x',
                '<ul> <li>a <ul> <li> <blockquote> <ul> <li>x</li> </ul> </blockquote> </li> </ul>'
                    . ' <h1 id="H">H</h1> </li> </ul>',
            ],
        ];
    }

    #[DataProvider('innerPrefixProvider')]
    public function testTheOpenerReachesTheOuterItemBehindEveryInnerPrefix(string $prefix, string $expected): void
    {
        $this->assertSame($expected, $this->html("- a\n  " . $prefix . "\n   # H\n"));
    }

    /**
     * A `>` at the head of the inner prefix is the control, not another row of
     * the table above: the opener line carries no `>`, so it reaches no column
     * inside the quote and is lazy paragraph text there whatever its own
     * indentation says.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function quoteHeadedPrefixProvider(): array
    {
        return [
            'a quote' => [
                '> x',
                '<ul> <li>a <blockquote><p>x # H</p></blockquote> </li> </ul>',
            ],
            'a quote over a marker' => [
                '> - x',
                '<ul> <li>a <blockquote> <ul> <li>x # H</li> </ul> </blockquote> </li> </ul>',
            ],
        ];
    }

    #[DataProvider('quoteHeadedPrefixProvider')]
    public function testAQuoteHeadedInnerPrefixFoldsTheOpenerAsText(string $prefix, string $expected): void
    {
        $this->assertSame($expected, $this->html("- a\n  " . $prefix . "\n   # H\n"));
    }

    /**
     * Every column the below-lead spelling makes live, and the one below all of
     * them. Column 1 reaches NO content column, so the line stays item text -
     * that is the boundary a fix written one column too wide crosses.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function columnProvider(): array
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

    #[DataProvider('columnProvider')]
    public function testADefinitionRegistersFromEveryColumnItReaches(int $column, bool $registers): void
    {
        $html = $this->html("- a\n  - x\n" . str_repeat(' ', $column) . "[r]: /url\n\nSee [r][].\n");

        if ($registers) {
            $this->assertSame(
                '<ul> <li>a <ul> <li>x</li> </ul> </li> </ul> <p>See <a href="/url">r</a>.</p>',
                $html,
            );

            return;
        }

        $this->assertSame(
            '<ul> <li>a <ul> <li>x [r]: /url</li> </ul> </li> </ul> <p>See [r][].</p>',
            $html,
        );
    }

    /**
     * The other control: a line carrying no `>` reaches no column inside a
     * quote whatever its own indentation says, so it is lazy paragraph text and
     * the definition never registers.
     */
    public function testAnUnmarkedLineReachesNoColumnInsideAQuote(): void
    {
        $this->assertSame(
            '<blockquote><p>a &gt; x [r]: /url</p></blockquote> <p>See [r][].</p>',
            $this->html("> a\n  > x\n   [r]: /url\n\nSee [r][].\n"),
        );
    }
}
