<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\FencedRenderExtension;
use Carve\SafeMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FencedRenderExtensionTest extends TestCase
{
    protected function render(string $djot, FencedRenderExtension $ext, ?SafeMode $safeMode = null): string
    {
        $converter = new CarveConverter(safeMode: $safeMode);
        $converter->addExtension($ext);

        return trim($converter->convert($djot));
    }

    public function testTextModeEscapesAmpersandAndLessThanButPreservesGreaterThan(): void
    {
        $djot = "``` d2\na -> b & <c\n```";

        // Text mode escapes & and <, but preserves > so arrow syntax survives
        // (matches MermaidExtension). The < escape already blocks tag injection.
        $this->assertSame(
            '<pre class="d2">a -> b &amp; &lt;c</pre>',
            $this->render($djot, FencedRenderExtension::d2()),
        );
    }

    public function testGraphvizPresetClaimsBothDotAndGraphviz(): void
    {
        $dot = $this->render("``` dot\na -> b\n```", FencedRenderExtension::graphviz());
        $graphviz = $this->render("``` graphviz\na -> b\n```", FencedRenderExtension::graphviz());

        $this->assertSame('<pre class="graphviz">a -> b</pre>', $dot);
        $this->assertSame('<pre class="graphviz">a -> b</pre>', $graphviz);
    }

    public function testJsonModeWrapsBodyInScriptTagInsideDiv(): void
    {
        $djot = "``` vega-lite\n{\"mark\": \"bar\"}\n```";

        $this->assertSame(
            '<div class="vega-lite"><script type="application/json">{"mark": "bar"}</script></div>',
            $this->render($djot, FencedRenderExtension::vegaLite()),
        );
    }

    public function testJsonModeGuardsScriptClose(): void
    {
        // A `</script>` (or any `</`) inside the JSON body must not close the
        // script element early; it is rewritten to `<\/` (byte-equivalent JSON).
        $djot = "``` vega-lite\n{\"x\": \"</script>\"}\n```";

        $html = $this->render($djot, FencedRenderExtension::vegaLite());

        $this->assertSame(
            '<div class="vega-lite"><script type="application/json">{"x": "<\/script>"}</script></div>',
            $html,
        );
        // Exactly one real closing tag (the wrapper's), none from the body.
        $this->assertSame(1, substr_count($html, '</script>'));
    }

    public function testJsonModeDefaultsToDivTag(): void
    {
        $ext = new FencedRenderExtension(language: 'chart', contentMode: FencedRenderExtension::MODE_JSON);
        $html = $this->render("``` chart\n{}\n```", $ext);

        $this->assertStringStartsWith('<div class="chart">', $html);
        $this->assertStringContainsString('<script type="application/json">{}</script>', $html);
    }

    public function testUnclaimedLanguageDefersToCoreCodeBlock(): void
    {
        $html = $this->render("``` python\nprint(1)\n```", FencedRenderExtension::d2());

        $this->assertStringContainsString('class="language-python"', $html);
        $this->assertStringNotContainsString('class="d2"', $html);
    }

    public function testMergesAuthorClasses(): void
    {
        $djot = "{.tall .wide}\n``` d2\na -> b\n```";

        $this->assertSame(
            '<pre class="d2 tall wide">a -> b</pre>',
            $this->render($djot, FencedRenderExtension::d2()),
        );
    }

    public function testCopiesAuthorAttributesWhenSafeModeOff(): void
    {
        $djot = "{#chart1 data-theme=\"dark\"}\n``` d2\na -> b\n```";

        $html = $this->render($djot, FencedRenderExtension::d2());

        $this->assertStringContainsString('id="chart1"', $html);
        $this->assertStringContainsString('data-theme="dark"', $html);
    }

    public function testStripsEventHandlerAlwaysOnEvenWithoutSafeMode(): void
    {
        // Always-on attribute hardening (the core renderer's baseline) strips
        // event handlers regardless of safe mode, so an `onclick` cannot ride
        // along on the raw output element while safe attributes survive.
        $djot = "{#chart1 .tall onclick=\"alert(1)\"}\n``` d2\na -> b\n```";

        $html = $this->render($djot, FencedRenderExtension::d2());

        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('class="d2 tall"', $html);
        $this->assertStringContainsString('id="chart1"', $html);
    }

    public function testSafeModeStrictStripsStyleOnTopOfBaseline(): void
    {
        // Safe mode strips ADDITIONAL names on top of the always-on baseline;
        // strict mode adds `style`.
        $djot = "{#chart1 style=\"color:red\"}\n``` d2\na -> b\n```";

        $html = $this->render($djot, FencedRenderExtension::d2(), SafeMode::strict());

        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringContainsString('id="chart1"', $html);
    }

    public function testAttributeValuesCannotBreakOutOfTheQuote(): void
    {
        // A double quote in a value is escaped, so it cannot terminate the
        // attribute and inject markup.
        $djot = "{title=\"a\\\" onmouseover=\\\"x\"}\n``` d2\na\n```";

        $html = $this->render($djot, FencedRenderExtension::d2());

        $this->assertStringNotContainsString('" onmouseover="x"', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    public function testWrapInFigure(): void
    {
        $ext = new FencedRenderExtension(language: 'd2', wrapInFigure: true);
        $html = $this->render("``` d2\na\n```", $ext);

        $this->assertStringContainsString('<figure class="d2-figure">', $html);
        $this->assertStringContainsString('<pre class="d2">a</pre>', $html);
        $this->assertStringContainsString('</figure>', $html);
    }

    public function testCustomTagAndCssClass(): void
    {
        $ext = new FencedRenderExtension(language: 'd2', cssClass: 'diagram', tag: 'div');

        $this->assertSame(
            '<div class="diagram">a -> b</div>',
            $this->render("``` d2\na -> b\n```", $ext),
        );
    }

    public function testMermaidPresetMatchesMermaidExtension(): void
    {
        $djot = "``` mermaid\ngraph TD; A-->B\n```";

        $viaPreset = $this->render($djot, FencedRenderExtension::mermaid());

        // `>` preserved (arrow syntax), identical to MermaidExtension today.
        $this->assertSame('<pre class="mermaid">graph TD; A-->B</pre>', $viaPreset);
    }

    public function testRoundTripPreservesFenceLabel(): void
    {
        // In round-trip mode the reconstructed source must keep a bracketed
        // label (```d2 [Diagram]); core code blocks do, so claimed fences must too.
        $converter = new CarveConverter(roundTripMode: true);
        $converter->addExtension(FencedRenderExtension::d2());

        $html = $converter->convert("``` d2 [Diagram]\na -> b\n```");

        $this->assertStringContainsString('data-djot-src="', $html);
        $this->assertStringContainsString('[Diagram]', $html);
    }

    public function testEmptyLanguageThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FencedRenderExtension(language: '');
    }

    public function testUnknownContentModeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FencedRenderExtension(language: 'd2', contentMode: 'binary');
    }

    public function testPresetsReturnsEveryBundledPreset(): void
    {
        $presets = FencedRenderExtension::presets();

        $this->assertCount(7, $presets);
        foreach ($presets as $preset) {
            $this->assertInstanceOf(FencedRenderExtension::class, $preset);
        }
    }

    public function testPresetsRegisterAllFenceLanguages(): void
    {
        $converter = new CarveConverter();
        $converter->addExtensions(FencedRenderExtension::presets());

        $mermaid = trim($converter->convert("``` mermaid\ngraph TD; A-->B\n```"));
        $this->assertStringContainsString('<pre class="mermaid">', $mermaid);

        $dot = trim($converter->convert("``` dot\ndigraph { a -> b }\n```"));
        $this->assertStringContainsString('<pre class="graphviz">', $dot);

        $chart = trim($converter->convert("``` chart\n{\"type\":\"bar\"}\n```"));
        $this->assertStringContainsString('<div class="chart">', $chart);
        $this->assertStringContainsString('<script type="application/json">', $chart);
    }
}
