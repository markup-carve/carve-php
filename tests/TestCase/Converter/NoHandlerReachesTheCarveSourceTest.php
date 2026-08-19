<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No event handler reaches the Carve SOURCE, from any writer.
 *
 * The importer's strip policy was a list of five handler names plus an `on*`
 * prefix rule that four separate writers each had to remember to apply. Three
 * did not, so `<aside class="admonition note" onfocus="steal()">` imported to
 * Carve source reading `{onfocus=steal()}` - the exact laundering the fourth
 * writer's comment says the prefix exists to prevent (carve-php#1375).
 *
 * ASSERTED ON THE SOURCE, never on rendered HTML. This engine's renderer strips
 * `on*` on the way out (PART 9 §25), so a render-level assertion passes while
 * the source is dirty - and the source is what gets stored, diffed, hand-edited
 * and rendered by other targets. A defense that holds only at the last stage is
 * one target away from not holding.
 *
 * THE HANDLERS ARE CHOSEN TO BE OUTSIDE ANY ENUMERATION. `onclick` would have
 * passed against the old code; `onfocus`, `onpointerdown` and `onanimationstart`
 * are the adversary's, and `ONERROR` is the same question in the case the DOM
 * hands back. `on*` is unbounded and browsers keep extending it, which is why a
 * roster cannot be the policy.
 *
 * ONE SHAPE PER WRITER, and each was found by removing the predicate at that
 * site alone and seeing which shape leaked. A test that reaches three of four
 * writers is a test whose fourth site has no coverage, which is how this defect
 * survived in the first place.
 */
class NoHandlerReachesTheCarveSourceTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function writerProvider(): array
    {
        return [
            // processDiv(), the generic colon-fence writer.
            'a div with a class' => ['<div class="custom" %s="steal()"><p>q</p></div>'],
            // processAside(), the admonition writer.
            'an admonition' => ['<aside class="admonition note" %s="steal()"><p>q</p></aside>'],
            // processAdmonition(), the writer this engine's OWN renderer feeds
            // on a round trip - a `<div class="admonition …">` rather than the
            // `<aside>` above, and a separate writer with a separate skip list.
            'a round-tripped admonition div' => ['<div class="admonition note" %s="steal()" data-djot-admonition-type="note"><p>q</p></div>'],
            // processLineBlock().
            'a line block' => ['<div class="line-block" %s="steal()"><p>a<br>b</p></div>'],
            // getElementAttributes(), the writer that always had the rule.
            'a paragraph' => ['<p %s="steal()">q</p>'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function handlerProvider(): array
    {
        return [
            'onfocus' => ['onfocus'],
            'onpointerdown' => ['onpointerdown'],
            'onanimationstart' => ['onanimationstart'],
            'uppercase ONERROR' => ['ONERROR'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function writerAndHandlerProvider(): array
    {
        $cases = [];
        foreach (self::writerProvider() as $writer => [$template]) {
            foreach (self::handlerProvider() as $label => [$handler]) {
                $cases[$writer . ', ' . $label] = [$template, $handler];
            }
        }

        return $cases;
    }

    #[DataProvider('writerAndHandlerProvider')]
    public function testNoWriterPutsAHandlerInTheSource(string $template, string $handler): void
    {
        $carve = (new HtmlToCarve())->convert(sprintf($template, $handler));

        $this->assertStringNotContainsStringIgnoringCase(
            $handler,
            $carve,
            'the handler reached the Carve source: ' . $carve,
        );
        $this->assertStringNotContainsString('steal()', $carve);
    }

    /**
     * The content around the handler is untouched: this refuses, it does not
     * narrow what is KEPT. carve-php#1337's ruling stands.
     */
    #[DataProvider('writerProvider')]
    public function testTheRestOfTheElementIsUnchanged(string $template): void
    {
        $withHandler = (new HtmlToCarve())->convert(sprintf($template, 'onfocus'));
        $without = (new HtmlToCarve())->convert(str_replace(' %s="steal()"', '', $template));

        $this->assertSame($without, $withHandler);
    }

    /**
     * An ordinary unknown attribute on the same elements still survives.
     *
     * The policy refuses strictly more than it did, never less - so the widened
     * refusal must not have taken the retention with it.
     */
    #[DataProvider('writerProvider')]
    public function testAnUnknownAttributeIsStillKept(string $template): void
    {
        $carve = (new HtmlToCarve())->convert(sprintf($template, 'data-keep'));

        $this->assertStringContainsString('data-keep=steal()', $carve);
    }

    /**
     * `srcdoc` and `formaction` are the importer's business too now.
     *
     * PART 9 §25 has the renderer blank both, so keeping them on import left
     * Carve source carrying an attribute every target must remember to refuse.
     * Nothing the reader sees changes - the renderer already blanked them.
     */
    #[DataProvider('rendererRefusedProvider')]
    public function testAnAttributeTheRendererRefusesIsNotImportedEither(string $name, string $value): void
    {
        $html = sprintf('<p %s="%s">q</p>', $name, $value);
        $carve = (new HtmlToCarve())->convert($html);

        $this->assertStringNotContainsStringIgnoringCase($name, $carve);
        $this->assertSame(
            "<p>q</p>\n",
            (new CarveConverter())->convert($carve),
            'and the rendered output is what it always was',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rendererRefusedProvider(): array
    {
        return [
            'srcdoc' => ['srcdoc', '&lt;script&gt;'],
            'formaction' => ['formaction', 'javascript:x'],
        ];
    }

    /**
     * The five handler names the list used to carry are gone, and nothing
     * depends on them being there.
     *
     * They were redundant wherever the prefix rule ran and the only defense
     * where it did not, which is the worst of both readings - and an
     * enumeration of `on*` names cannot be complete anyway.
     */
    public function testTheHandlerNamesAreNoLongerEnumerated(): void
    {
        $converter = new class extends HtmlToCarve {
            /**
             * @return array<string>
             */
            public function policyList(): array
            {
                return $this->skipAttributes;
            }

            public function strips(string $name): bool
            {
                return $this->isStrippedImportAttribute($name);
            }
        };

        foreach ($converter->policyList() as $name) {
            $this->assertStringStartsNotWith(
                'on',
                $name,
                'a handler name in the list is a roster pretending to be a rule',
            );
        }
        $this->assertTrue($converter->strips('onclick'), 'the rule still refuses the names the roster held');
        $this->assertTrue($converter->strips('onfocus'), 'and the ones it never held');
    }
}
