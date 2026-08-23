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
}
