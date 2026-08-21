<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 §2 states the two escapings apart, and they are not the same rule:
 *
 *   In an attribute value escape `&`, `<`, `>`, `"` and `'`.
 *   In text/content escape `&`, `<` and `>` and NOT quotes.
 *
 * A tab's `[label]` is a plain string that never goes through inline parsing,
 * so a straight double quote in one survives to the escaper. The extensions
 * reached for `StringUtil::escapeHtml()`, which passes `ENT_QUOTES`, and wrote
 * `&quot;` into ELEMENT TEXT - which §2 does not permit and which no sibling
 * engine writes (markup-carve/carve-php#1538).
 *
 * In ordinary prose the divergence is invisible: smart typography has already
 * turned a straight quote into a curly one by the time text is rendered, so
 * core paragraphs agreed across engines the whole time.
 *
 * The panel's accessible NAME is the same string in the other position, and it
 * keeps `&quot;` - an attribute value must escape the quote. Both directions are
 * asserted here, because a fix that unescaped the attribute too would pass a
 * one-sided test.
 */
class AnExtensionLabelIsTextNotAnAttributeValueTest extends TestCase
{
    protected const SPEC_CORPUS = __DIR__ . '/../../spec/tests/corpus-optional/';

    protected function convert(string $source, object $extension, ?string $mode = null): string
    {
        $converter = $mode !== null ? new CarveConverter(mode: $mode) : new CarveConverter();
        $converter->addExtension($extension);

        return $converter->convert($source);
    }

    /**
     * Every position a label is rendered as element text.
     *
     * @return array<string, array{0: string, 1: object, 2: string|null, 3: string}>
     */
    public static function labelTextProvider(): array
    {
        $tabs = ":::: tabs\n::: tab [a \"q\" b]\nx\n:::\n::::\n";
        $group = "::: code-group\n``` js [a \"q\" b]\n1\n```\n:::\n";

        return [
            'tabs css label' => [$tabs, new TabsExtension(), null, '</label>'],
            'tabs aria button' => [$tabs, new TabsExtension(mode: TabsExtension::MODE_ARIA), null, '</button>'],
            'tabs static heading' => [$tabs, new TabsExtension(), 'static', '</h3>'],
            'code group css label' => [$group, new CodeGroupExtension(), null, '</label>'],
            'code group aria button' => [
                $group,
                new CodeGroupExtension(mode: CodeGroupExtension::MODE_ARIA),
                null,
                '</button>',
            ],
            'code group static label' => [$group, new CodeGroupExtension(), 'static', '</h3>'],
        ];
    }

    #[DataProvider('labelTextProvider')]
    public function testALabelInElementTextKeepsItsQuote(
        string $source,
        object $extension,
        ?string $mode,
        string $closer,
    ): void {
        $html = $this->convert($source, $extension, $mode);

        $this->assertStringContainsString('a "q" b' . $closer, $html);
        $this->assertStringNotContainsString('a &quot;q&quot; b' . $closer, $html);
    }

    /**
     * The other direction: the same string as an attribute value DOES escape.
     */
    public function testTheSameStringAsAnAccessibleNameIsAttributeEscaped(): void
    {
        $html = $this->convert(":::: tabs\n::: tab [a \"q\" b]\nx\n:::\n::::\n", new TabsExtension());

        $this->assertStringContainsString('aria-label="a &quot;q&quot; b"', $html);
        $this->assertStringNotContainsString('aria-label="a "q" b"', $html);
    }

    /**
     * The `<` and `&` half of §2 is unchanged - text content still escapes
     * those, so this is a narrowing of the escaping and not its removal.
     */
    public function testAngleBracketsAndAmpersandsStillEscapeInText(): void
    {
        $html = $this->convert(":::: tabs\n::: tab [R&D <x>]\nc\n:::\n::::\n", new TabsExtension());

        $this->assertStringContainsString('R&amp;D &lt;x&gt;</label>', $html);
    }

    /**
     * The two spec fixtures this turned on, byte for byte.
     *
     * `46-tabs-css-panel-name` and `47-tabs-aria-panel-binding` land with
     * markup-carve/carve#1489 and both carry `[R&D "core" <x>]` deliberately.
     * Case 47's control gained `type="button"` in markup-carve/carve#1504
     * (Extensions §13.3), so the bytes below are spec main's, not the ones
     * that PR replaced.
     * They are NOT in the pinned corpus yet, so the corpus runner cannot reach
     * them - and a test that read them off disk would SKIP, which is a check
     * that cannot fail. The bytes are inlined from spec main instead, the
     * same way `OptionalCorpusTest::AHEAD_OF_PIN` states what this engine
     * writes ahead of the pin.
     *
     * The guard below fails when the pin catches up, so these two die in the
     * commit that moves it.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function specFixtureProvider(): array
    {
        return [
            '46 css panel name' => [
                ":::: tabs\n::: tab [First]\nContent one.\n:::\n::: tab [R&D \"core\" <x>]\nContent two.\n:::\n::::\n",
                "<div class=\"tabs\" role=\"group\" aria-label=\"Tabs\">\n<input type=\"radio\" name=\"tabset-1\" id=\"tabset-1-tab-1\" class=\"tabs-radio\" checked>\n<label for=\"tabset-1-tab-1\" class=\"tabs-label\">First</label>\n<input type=\"radio\" name=\"tabset-1\" id=\"tabset-1-tab-2\" class=\"tabs-radio\">\n<label for=\"tabset-1-tab-2\" class=\"tabs-label\">R&amp;D \"core\" &lt;x&gt;</label>\n<div class=\"tabs-panel\" role=\"group\" aria-label=\"First\">\n<p>Content one.</p>\n</div>\n<div class=\"tabs-panel\" role=\"group\" aria-label=\"R&amp;D &quot;core&quot; &lt;x&gt;\">\n<p>Content two.</p>\n</div>\n</div>\n",
                TabsExtension::MODE_CSS,
            ],
            '47 aria panel binding' => [
                ":::: tabs\n::: tab [First]\nContent one.\n:::\n::: tab [R&D \"core\" <x>]\nContent two.\n:::\n::::\n",
                "<div class=\"tabs\" role=\"tablist\" aria-label=\"Tabs\">\n<button type=\"button\" role=\"tab\" id=\"tabset-1-tab-1\" aria-selected=\"true\" aria-controls=\"tabset-1-panel-1\" class=\"tabs-label\">First</button>\n<button type=\"button\" role=\"tab\" id=\"tabset-1-tab-2\" aria-selected=\"false\" aria-controls=\"tabset-1-panel-2\" class=\"tabs-label\" tabindex=\"-1\">R&amp;D \"core\" &lt;x&gt;</button>\n<div role=\"tabpanel\" id=\"tabset-1-panel-1\" aria-labelledby=\"tabset-1-tab-1\" class=\"tabs-panel\">\n<p>Content one.</p>\n</div>\n<div role=\"tabpanel\" id=\"tabset-1-panel-2\" aria-labelledby=\"tabset-1-tab-2\" class=\"tabs-panel\" hidden>\n<p>Content two.</p>\n</div>\n</div>\n",
                TabsExtension::MODE_ARIA,
            ],
        ];
    }

    #[DataProvider('specFixtureProvider')]
    public function testTheSpecFixtureMatchesByteForByte(string $crv, string $expected, string $mode): void
    {
        $this->assertSame($expected, $this->convert($crv, new TabsExtension(mode: $mode)));
    }

    /**
     * The pin does not state these two cases yet, which is why their bytes are
     * inlined above. When it does, the corpus runner owns them and the pair
     * becomes a second statement of the same thing - so this fails and says so.
     */
    public function testThePinDoesNotStateTheseCasesYet(): void
    {
        $this->assertFileDoesNotExist(
            self::SPEC_CORPUS . '46-tabs-css-panel-name.crv',
            'the pin has caught up: delete the two inlined fixture cases, OptionalCorpusTest owns them now',
        );
    }
}
