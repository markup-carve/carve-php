<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Extensions §13.3 and §13.5, ruled on markup-carve/carve-php#1537 and stated
 * in the spec by markup-carve/carve#1504. Both rules bind BOTH constructs, so
 * every case here runs against Tabs and CodeGroup alike: §13 exists to stop the
 * two renderers drifting, and a rule tested on one of them is a rule that can.
 *
 * §13.3 - the generated control is `type="button"`. A `<button>` with no `type`
 * is a SUBMIT button, so a tab set inside a `<form>` submitted the form when a
 * tab was activated, instead of switching panels.
 *
 * §13.5 - exactly one item is selected: the first one the document marks
 * `{selected}`, and the first item where it marks none. Later marks are
 * IGNORED, and over-specifying is not an error - no diagnostic, because §13 has
 * no diagnostic channel and the document is redundant rather than wrong.
 *
 * carve-js behaves as this engine did on both, measured against a build of
 * `8fb450d8`, so neither was a carve-php divergence.
 */
class ATabControlIsAButtonAndOneItemIsSelectedTest extends TestCase
{
    /**
     * Marks the SECOND and THIRD items, never the first.
     *
     * That is the whole design of the fixture. Marking the first as well would
     * make first-wins indistinguishable from the default-the-first branch, and
     * a document where the last mark is also the winner cannot tell first-wins
     * from last-wins. Only a middle winner separates the ruling from both of
     * the rules it was chosen over.
     *
     * @var string
     */
    protected const TABS_TWO_MARKED = ":::: tabs\n::: tab [First]\nContent one.\n:::\n\n{selected}\n"
        . "::: tab [Second]\nContent two.\n:::\n\n{selected}\n::: tab [Third]\nContent three.\n:::\n::::\n";

    /**
     * @var string
     */
    protected const CODE_GROUP_TWO_MARKED = "::: code-group\n``` js [Node]\nconsole.log(1)\n```\n\n{selected}\n"
        . "``` python [Py]\nprint(1)\n```\n\n{selected}\n``` ruby [Rb]\nputs 1\n```\n:::\n";

    /**
     * @var string
     */
    protected const TABS_UNMARKED = ":::: tabs\n::: tab [First]\nContent one.\n:::\n\n"
        . "::: tab [Second]\nContent two.\n:::\n::::\n";

    /**
     * @var string
     */
    protected const CODE_GROUP_UNMARKED = "::: code-group\n``` js [Node]\nconsole.log(1)\n```\n\n"
        . "``` python [Py]\nprint(1)\n```\n:::\n";

    protected function convert(string $source, object $extension): string
    {
        $converter = new CarveConverter();
        $converter->addExtension($extension);

        return $converter->convert($source);
    }

    /**
     * @return array<string, array{0: string, 1: object}>
     */
    public static function ariaProvider(): array
    {
        return [
            'tabs' => [self::TABS_UNMARKED, new TabsExtension(mode: TabsExtension::MODE_ARIA)],
            'code group' => [
                self::CODE_GROUP_UNMARKED,
                new CodeGroupExtension(mode: CodeGroupExtension::MODE_ARIA),
            ],
        ];
    }

    /**
     * §13.3: EVERY generated control says `type="button"`.
     *
     * Asserted as an absence too. The positive alone passes an engine that
     * writes the attribute on the selected control and leaves the rest bare,
     * which is the shape a "fix the example in the docs" change produces.
     */
    #[DataProvider('ariaProvider')]
    public function testEveryAriaControlIsATypeButton(string $source, object $extension): void
    {
        $html = $this->convert($source, $extension);

        $this->assertSame(2, substr_count($html, '<button type="button" role="tab"'));
        $this->assertStringNotContainsString('<button role="tab"', $html);
    }

    /**
     * The `css` mode has no button to fix, and gains none.
     *
     * Its control is an `<input type="radio">`, which already says what it is.
     * Without this the rule could be "read" as "tab sets emit buttons now".
     *
     * @return array<string, array{0: string, 1: object}>
     */
    public static function cssProvider(): array
    {
        return [
            'tabs' => [self::TABS_UNMARKED, new TabsExtension()],
            'code group' => [self::CODE_GROUP_UNMARKED, new CodeGroupExtension()],
        ];
    }

    #[DataProvider('cssProvider')]
    public function testTheCssModeStillEmitsNoButtonAtAll(string $source, object $extension): void
    {
        $html = $this->convert($source, $extension);

        $this->assertStringNotContainsString('<button', $html);
        $this->assertSame(2, substr_count($html, '<input type="radio"'));
    }

    /**
     * §13.5 in `aria` mode: the FIRST mark wins, in both constructs.
     *
     * The count assertion is the one that fails today: two marks used to give
     * two `aria-selected="true"` tabs, a shape a single-select `tablist` has no
     * state for. The `tabindex` half goes with it - a tab that is not selected
     * is out of the tab order, so an unfixed engine also left two normal tab
     * stops in the set.
     *
     * @return array<string, array{0: string, 1: object, 2: string}>
     */
    public static function ariaTwoMarkedProvider(): array
    {
        return [
            'tabs' => [
                self::TABS_TWO_MARKED,
                new TabsExtension(mode: TabsExtension::MODE_ARIA),
                'tabset-1',
            ],
            'code group' => [
                self::CODE_GROUP_TWO_MARKED,
                new CodeGroupExtension(mode: CodeGroupExtension::MODE_ARIA),
                'codegroup-1',
            ],
        ];
    }

    #[DataProvider('ariaTwoMarkedProvider')]
    public function testTheFirstMarkWinsInAriaMode(string $source, object $extension, string $set): void
    {
        $html = $this->convert($source, $extension);

        $this->assertSame(1, substr_count($html, 'aria-selected="true"'));
        $this->assertSame(2, substr_count($html, 'aria-selected="false"'));
        $this->assertSame(2, substr_count($html, 'tabindex="-1"'));

        // The winner is the SECOND item: not the first, which is what the
        // default would have chosen, and not the third, which is what last-wins
        // would have.
        $this->assertStringContainsString(
            'id="' . $set . '-tab-2" aria-selected="true"',
            $html,
        );
        $this->assertStringContainsString('id="' . $set . '-tab-1" aria-selected="false"', $html);
        $this->assertStringContainsString('id="' . $set . '-tab-3" aria-selected="false"', $html);

        // ...and the reveal follows the selection: two panels hidden, one not.
        $this->assertSame(2, substr_count($html, ' hidden>'));
    }

    /**
     * §13.5 in `css` mode, on the SAME document, selecting the SAME item.
     *
     * This is the half that makes the ruling a ruling. A radio group cannot
     * have two checked members - the browser resolves it to one whatever the
     * markup says - so `css` never rendered the over-specified document
     * differently, and first-wins was chosen because it is what the `css`
     * default already does with `checked`. If the two modes could disagree
     * about which tab opens, there would be no reason to prefer it.
     *
     * @return array<string, array{0: string, 1: object, 2: string}>
     */
    public static function cssTwoMarkedProvider(): array
    {
        return [
            'tabs' => [self::TABS_TWO_MARKED, new TabsExtension(), 'tabset-1'],
            'code group' => [self::CODE_GROUP_TWO_MARKED, new CodeGroupExtension(), 'codegroup-1'],
        ];
    }

    #[DataProvider('cssTwoMarkedProvider')]
    public function testTheFirstMarkWinsInCssModeToo(string $source, object $extension, string $set): void
    {
        $html = $this->convert($source, $extension);

        $this->assertSame(1, substr_count($html, ' checked>'));
        $this->assertStringContainsString('id="' . $set . '-tab-2" class=', $html);
        $this->assertMatchesRegularExpression(
            '/id="' . preg_quote($set, '/') . '-tab-2" class="[a-z-]+" checked>/',
            $html,
        );
    }

    /**
     * Marking NOTHING still opens the first item, in both modes.
     *
     * The default branch and the first-wins branch are one statement now, so
     * this is the case that would break if the collapse were written as "drop
     * every mark after the first" without the fallback.
     *
     * @return array<string, array{0: string, 1: object, 2: string}>
     */
    public static function unmarkedProvider(): array
    {
        return [
            'tabs aria' => [
                self::TABS_UNMARKED,
                new TabsExtension(mode: TabsExtension::MODE_ARIA),
                'aria-selected="true"',
            ],
            'tabs css' => [self::TABS_UNMARKED, new TabsExtension(), ' checked>'],
            'code group aria' => [
                self::CODE_GROUP_UNMARKED,
                new CodeGroupExtension(mode: CodeGroupExtension::MODE_ARIA),
                'aria-selected="true"',
            ],
            'code group css' => [self::CODE_GROUP_UNMARKED, new CodeGroupExtension(), ' checked>'],
        ];
    }

    #[DataProvider('unmarkedProvider')]
    public function testAnUnmarkedSetStillOpensItsFirstItem(string $source, object $extension, string $needle): void
    {
        $html = $this->convert($source, $extension);

        $this->assertSame(1, substr_count($html, $needle));
        // The FIRST control carries it: the marker appears before the second
        // control's id does.
        $this->assertLessThan(
            (int)strpos($html, '-tab-2"'),
            (int)strpos($html, $needle),
        );
    }

    /**
     * Over-specifying is NOT an error: no exception, no diagnostic, no
     * marker in the output. §13 has no diagnostic channel and the document is
     * redundant, not wrong.
     */
    #[DataProvider('ariaTwoMarkedProvider')]
    public function testOverSpecifyingIsNotDiagnosed(string $source, object $extension, string $set): void
    {
        $html = $this->convert($source, $extension);

        $this->assertStringContainsString('id="' . $set . '-tab-3"', $html);
        $this->assertStringNotContainsString('data-error', $html);
        $this->assertStringNotContainsString('carve-error', $html);
    }
}
