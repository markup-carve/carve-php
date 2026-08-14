<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Extension\SemanticSpanExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HTML import spells the seven semantic elements as the compact span attribute.
 *
 * `<kbd>Tab</kbd>` used to import as `Tab`, with an `element-unwrapped`
 * diagnostic recording the loss, and `<time datetime="X">` lost its `datetime`
 * one step earlier still. Carve can express all seven exactly, so they map.
 */
class HtmlImportSemanticSpanTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function elementProvider(): array
    {
        return [
            // The three core names.
            'kbd' => ['<p>Press <kbd>Tab</kbd></p>', 'Press [Tab]{kbd}'],
            'abbr with title' => ['<p><abbr title="HyperText">HTML</abbr></p>', '[HTML]{abbr="HyperText"}'],
            'time with datetime' => ['<p><time datetime="2026-01-01">today</time></p>', '[today]{time="2026-01-01"}'],
            // The four SemanticSpanExtension adds.
            'samp' => ['<p><samp>out</samp></p>', '[out]{samp}'],
            'var' => ['<p><var>v</var></p>', '[v]{var}'],
            'cite' => ['<p><cite>C</cite></p>', '[C]{cite}'],
            'dfn with title' => [
                '<p><dfn title="Cascading Style Sheets">CSS</dfn></p>',
                '[CSS]{dfn="Cascading Style Sheets"}',
            ],
            // A name that can carry a value, carrying none, is the bare boolean.
            'abbr without title' => ['<p><abbr>HTML</abbr></p>', '[HTML]{abbr}'],
            'dfn without title' => ['<p><dfn>d</dfn></p>', '[d]{dfn}'],
            'time without datetime' => ['<p><time>today</time></p>', '[today]{time}'],
        ];
    }

    #[DataProvider('elementProvider')]
    public function testSpellsEachElementAsTheCompactSpanAttribute(string $html, string $expected): void
    {
        $this->assertSame($expected, trim((new HtmlToCarve())->convert($html)));
    }

    /**
     * The loss stops being reported because there is no longer one. `<time>`
     * had two diagnostics: `datetime` was dropped as an unsupported attribute
     * before the element was unwrapped.
     */
    #[DataProvider('elementProvider')]
    public function testStopsReportingTheLoss(string $html, string $expected): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame($expected, trim($result->value));
        $this->assertSame([], array_column($result->report()['diagnostics'], 'code'));
    }

    /**
     * All three modes map these by construction: none of the seven is active
     * content, so `safe` has nothing to withhold, and carve-php's `roundtrip`
     * has no automatic raw-HTML fallback to divert them into.
     */
    #[DataProvider('elementProvider')]
    public function testMapsIdenticallyInAllThreeModes(string $html, string $expected): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);

            $this->assertSame($expected, trim($result->value), $mode);
            $this->assertSame([], array_column($result->report()['diagnostics'], 'code'), $mode);
        }
    }

    /**
     * A leftover `id`/`class` rides the same span rather than forcing a second
     * one, and the attribute the value came from is CONSUMED rather than left
     * beside it as a duplicate key.
     */
    public function testLeftoverAttributesRideTheSameSpan(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><abbr class="x" id="z" title="y">A</abbr></p>');

        $this->assertSame('[A]{abbr="y" #z .x}', trim($result->value));
        $this->assertSame([], array_column($result->report()['diagnostics'], 'code'));
    }

    public function testConsumesTheDatetimeRatherThanRepeatingIt(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><time id="z" datetime="2026-01-01">today</time></p>');

        $this->assertSame('[today]{time="2026-01-01" #z}', trim($result->value));
        $this->assertSame([], array_column($result->report()['diagnostics'], 'code'));
    }

    /**
     * An empty value attribute gives the bare boolean, the same as an absent
     * one, and it does so for all three names that can carry a value rather
     * than for some of them.
     *
     * This is the converter's global rule for an empty attribute value, not a
     * rule invented for these names: `<span foo="">` has always imported as
     * `{foo}`. Spelling `<time datetime="">` as `time=""` would make `time` the
     * only name in the set that reads an empty value differently from the
     * `<abbr title="">` beside it.
     */
    public function testAnEmptyValueAttributeIsTheBareBooleanForEveryNameThatCanCarryOne(): void
    {
        $converter = new HtmlToCarve();

        $this->assertSame('[x]{abbr}', trim($converter->convert('<p><abbr title="">x</abbr></p>')));
        $this->assertSame('[x]{dfn}', trim($converter->convert('<p><dfn title="">x</dfn></p>')));
        $this->assertSame('[x]{time}', trim($converter->convert('<p><time datetime="">x</time></p>')));
        $this->assertSame('[x]{foo}', trim($converter->convert('<p><span foo="">x</span></p>')));
    }

    /**
     * The compact form is one node, so it nests.
     */
    public function testNestsBecauseTheCompactFormDoes(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><kbd><kbd>Ctrl</kbd>+<kbd>C</kbd></kbd></p>');

        $this->assertSame('[[Ctrl]{kbd}+[C]{kbd}]{kbd}', trim($result->value));
        $this->assertSame([], array_column($result->report()['diagnostics'], 'code'));
    }

    /**
     * The tier split, stated rather than implied. The three core names come
     * back byte for byte; the four the extension carries come back as the
     * attribute unless the processor registers SemanticSpanExtension.
     *
     * That is still strictly better than the unwrap it replaces, where the
     * semantic was discarded outright instead of surviving as an attribute a
     * reader can recover.
     */
    public function testCoreNameRoundTripsExactlyAndAnExtensionNameDoesNot(): void
    {
        $core = new CarveConverter();
        $withExtension = new CarveConverter();
        $withExtension->addExtension(new SemanticSpanExtension());

        $kbd = trim((new HtmlToCarve())->convert('<p>Press <kbd>Tab</kbd></p>'));
        $this->assertSame('Press [Tab]{kbd}', $kbd);
        $this->assertSame('<p>Press <kbd>Tab</kbd></p>', trim($core->convert($kbd)));
        $this->assertSame('<p>Press <kbd>Tab</kbd></p>', trim($withExtension->convert($kbd)));

        $samp = trim((new HtmlToCarve())->convert('<p><samp>out</samp></p>'));
        $this->assertSame('[out]{samp}', $samp);
        $this->assertSame('<p><span samp="">out</span></p>', trim($core->convert($samp)));
        $this->assertSame('<p><samp>out</samp></p>', trim($withExtension->convert($samp)));
    }

    /**
     * The carve-outs the ruling names. `<mark>` keeps its highlight spelling,
     * inline `<code>` keeps its code span, and `<code>` inside `<pre>` keeps
     * going to a code block: the compact form is the inline case only.
     */
    public function testLeavesMarkInlineCodeAndACodeBlockAlone(): void
    {
        $converter = new HtmlToCarve();

        $this->assertSame('=m=', trim($converter->convert('<p><mark>m</mark></p>')));
        $this->assertSame('`c`', trim($converter->convert('<p><code>c</code></p>')));
        $this->assertSame(
            "```js\nx()\n```",
            trim($converter->convert('<pre><code class="language-js">x()</code></pre>')),
        );
    }

    /**
     * A `<cite>` that is the quote's ATTRIBUTION keeps going to the caption
     * line below the quote. Only an ordinary inline `<cite>` becomes a span.
     */
    public function testABlockQuoteAttributionCiteStaysAnAttribution(): void
    {
        $converter = new HtmlToCarve();

        $this->assertSame(
            "> q\n^ Author",
            trim($converter->convert('<blockquote><p>q</p><cite>Author</cite></blockquote>')),
        );
        $this->assertSame(
            "> [C]{cite} said it\n^ Author",
            trim($converter->convert(
                '<blockquote><p><cite>C</cite> said it</p><cite>Author</cite></blockquote>',
            )),
        );
    }

    /**
     * The wider inline set has no semantic span name, so it still unwraps and
     * still says so. Without this the element count could drift without the
     * suite noticing.
     */
    public function testTheWiderInlineSetStillUnwraps(): void
    {
        $converter = new HtmlToCarve();

        foreach (['small', 'bdi', 'ruby', 'output'] as $tag) {
            $result = $converter->convertWithReport('<p><' . $tag . '>x</' . $tag . '></p>');

            $this->assertSame('x', trim($result->value), $tag);
            $this->assertSame(
                ['element-unwrapped'],
                array_column($result->report()['diagnostics'], 'code'),
                $tag,
            );
        }
    }
}
