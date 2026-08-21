<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Extensions §13, ruled in markup-carve/carve#1489 and shipped in carve-js as
 * carve-js#1266. Every rule here binds BOTH constructs: two constructs of the
 * same shape do not get different accessibility ceilings because one of them
 * was written second.
 *
 * §13.2 - a `css`-mode panel is NAMED. Under `css` there are no tab roles, so
 * nothing binds a panel to the control that reveals it: every radio and label
 * is emitted before every panel, and the panel itself is anonymous. It takes
 * `role="group"` - the control IS a radio, not a tab - and its name is the
 * tab's own label, attribute-escaped. Derived from the document, so §1.5 gives
 * it no `labels` key.
 *
 * §13.3 - an `aria`-mode panel is BOUND, NOT NAMED. It keeps `role="tabpanel"`
 * and `aria-labelledby` and takes NEITHER `role="group"` nor a name. That
 * absence is a rule, not an omission: naming it too would give one element two
 * accessible names and pull it out of the `tablist` relationship that is the
 * only reason to be in this mode. Without the near-miss below, the naive
 * reading - "every panel gets a name" - passes.
 *
 * §13.1 - `css` is the default in both, and an unknown mode is refused rather
 * than guessed.
 *
 * Measured byte for byte against a build of carve-js `8fb450d8`.
 */
class ACssModePanelIsNamedAndAnAriaOneIsBoundTest extends TestCase
{
    /**
     * @var string
     */
    protected const TABS = ":::: tabs\n::: tab [First]\nContent one.\n:::\n\n::: tab [Second]\nContent two.\n:::\n::::\n";

    /**
     * @var string
     */
    protected const CODE_GROUP = "::: code-group\n``` js [Node]\nconsole.log(1)\n```\n\n``` python\nprint(1)\n```\n:::\n";

    protected function convert(string $source, object $extension): string
    {
        $converter = new CarveConverter();
        $converter->addExtension($extension);

        return $converter->convert($source);
    }

    public function testACssTabsPanelIsNamedByItsOwnTab(): void
    {
        $html = $this->convert(self::TABS, new TabsExtension());

        $this->assertStringContainsString('<div class="tabs-panel" role="group" aria-label="First">', $html);
        $this->assertStringContainsString('<div class="tabs-panel" role="group" aria-label="Second">', $html);
    }

    public function testACssCodeGroupPanelIsNamedByItsOwnLabel(): void
    {
        $html = $this->convert(self::CODE_GROUP, new CodeGroupExtension());

        // The tab name where one was written...
        $this->assertStringContainsString('<div class="code-group-panel" role="group" aria-label="Node">', $html);
        // ...otherwise the language word.
        $this->assertStringContainsString('<div class="code-group-panel" role="group" aria-label="python">', $html);
    }

    /**
     * The name is TEXT, escaped for an ATTRIBUTE.
     *
     * Not the same escaper the label ELEMENT's content uses: that one turns a
     * literal non-breaking space into `&nbsp;`, which an attribute value must
     * keep as the character. Both halves are asserted, so swapping one escaper
     * for the other fails, and so does dropping the escaping entirely.
     */
    public function testThePanelNameIsEscapedForAnAttribute(): void
    {
        $source = ":::: tabs\n::: tab [R&D \"core\" <x>]\nc\n:::\n::::\n";
        $html = $this->convert($source, new TabsExtension());

        $this->assertStringContainsString(
            '<div class="tabs-panel" role="group" aria-label="R&amp;D &quot;core&quot; &lt;x&gt;">',
            $html,
        );

        $nbsp = ":::: tabs\n::: tab [a\u{00A0}b]\nc\n:::\n::::\n";
        $withNbsp = $this->convert($nbsp, new TabsExtension());

        $this->assertStringContainsString('>a&nbsp;b</label>', $withNbsp);
        $this->assertStringContainsString("aria-label=\"a\u{00A0}b\"", $withNbsp);
    }

    /**
     * THE NEAR-MISS that makes §13.3 a rule rather than an omission.
     *
     * @return array<string, array{0: string, 1: object, 2: string}>
     */
    public static function ariaModeProvider(): array
    {
        return [
            'tabs' => [self::TABS, new TabsExtension(mode: TabsExtension::MODE_ARIA), 'tabs-panel'],
            'code group' => [
                self::CODE_GROUP,
                new CodeGroupExtension(mode: CodeGroupExtension::MODE_ARIA),
                'code-group-panel',
            ],
        ];
    }

    #[DataProvider('ariaModeProvider')]
    public function testAnAriaPanelIsBoundAndNotNamed(string $source, object $extension, string $panelClass): void
    {
        $html = $this->convert($source, $extension);

        $this->assertStringContainsString('role="tabpanel"', $html);
        $this->assertStringContainsString('aria-labelledby="', $html);
        $this->assertStringContainsString('class="' . $panelClass . '" hidden>', $html);

        // The absence IS the rule. A panel that also carried role="group" or a
        // name would have two accessible names.
        $this->assertStringNotContainsString('class="' . $panelClass . '" role="group"', $html);
        $this->assertStringNotContainsString($panelClass . '" role="group" aria-label', $html);
        $this->assertSame(1, substr_count($html, 'aria-label="'), 'only the SET is named in aria mode');
    }

    /**
     * @return array<string, array{0: object}>
     */
    public static function cssDefaultProvider(): array
    {
        return [
            'tabs' => [new TabsExtension()],
            'code group' => [new CodeGroupExtension()],
        ];
    }

    /**
     * §13.1: `css` is the default in BOTH, and not for compatibility. §2.5's
     * rule is that content is never dropped, only interaction - and `aria` mode
     * reveals with `hidden`, so a page that registers it and ships no script
     * loses every panel but the first, while `css` with no stylesheet at all
     * shows every panel.
     */
    #[DataProvider('cssDefaultProvider')]
    public function testCssIsTheDefaultMode(object $extension): void
    {
        $source = $extension instanceof TabsExtension ? self::TABS : self::CODE_GROUP;
        $html = $this->convert($source, $extension);

        $this->assertStringContainsString('type="radio"', $html);
        $this->assertStringNotContainsString('role="tab"', $html);
        $this->assertStringNotContainsString(' hidden>', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badModeProvider(): array
    {
        return [
            'a typo' => ['arai'],
            'the right word, wrong case' => ['ARIA'],
            'empty' => [''],
            'a plausible third mode' => ['static'],
        ];
    }

    /**
     * §13.1: an unknown mode is REFUSED, not guessed. Both dispatches read
     * `mode === aria ? aria : css`, so before this a typo rendered the CSS mode
     * and the host had no way to find out.
     */
    #[DataProvider('badModeProvider')]
    public function testAnUnknownTabsModeIsRefused(string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TabsExtension(mode: $mode);
    }

    #[DataProvider('badModeProvider')]
    public function testAnUnknownCodeGroupModeIsRefused(string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CodeGroupExtension(mode: $mode);
    }

    /**
     * The new mode does not cost a capability the old one had.
     *
     * Round-trip metadata is emitted by both Tabs renderers and by the CSS
     * code-group one, so an `aria` code group that dropped it would break the
     * HTML -> Carve round trip on a mode switch alone - the shape §13 exists to
     * prevent, one layer down: two renderers of the same construct do not get
     * different capabilities because one was written second.
     */
    public function testTheAriaCodeGroupKeepsItsRoundTripMetadata(): void
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $converter = CarveConverter::create(renderer: $renderer);
        $converter->addExtension(new CodeGroupExtension(mode: CodeGroupExtension::MODE_ARIA));

        $html = $converter->convert(self::CODE_GROUP);

        $this->assertStringContainsString('data-djot-src="', $html);
        $this->assertStringContainsString('::: code-group', $html);
        $this->assertStringContainsString('role="tablist"', $html);
    }

    /**
     * A `"static"` render takes NEITHER mode (§13.1): the flattened
     * `<section>` is headed by its label, the heading IS the name, and no
     * interaction survives to bind. So no panel role and no panel name there.
     */
    public function testAStaticRenderNamesNoPanel(): void
    {
        $converter = new CarveConverter(mode: 'static');
        $converter->addExtension(new TabsExtension());
        $html = $converter->convert(self::TABS);

        $this->assertStringContainsString('<section class="tabs-panel">', $html);
        $this->assertStringNotContainsString('tabs-panel" role="group"', $html);
        $this->assertStringNotContainsString('role="tabpanel"', $html);
    }
}
