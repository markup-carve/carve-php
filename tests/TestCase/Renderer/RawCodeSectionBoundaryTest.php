<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\SafeMode;
use PHPUnit\Framework\TestCase;

class RawCodeSectionBoundaryTest extends TestCase
{
    public function testUnclosedCodeDoesNotReceiveTheSectionClosingSeparator(): void
    {
        foreach (['<code>', '<CODE>', '<code class="x">', '<code title="<code>">', '<code/>'] as $tag) {
            $source = '# `' . $tag . '`{=html}x';
            $html = (new CarveConverter())->convert($source);
            $this->assertStringContainsString('<h1>' . $tag . 'x</h1></section>' . "\n", $html);
        }
    }

    public function testImportedCodeNewlinesStayInsideTheHeading(): void
    {
        $migration = (new MarkdownToCarve())->convertWithFidelityReport('# <code>a&#10;b');
        $this->assertSame(
            '<section id="ab">' . "\n  " . '<h1><code>a<!---->&#10;<!---->b</h1></section>' . "\n",
            (new CarveConverter())->convert($migration->value),
        );
    }

    public function testClosedCodeKeepsTheSectionLayout(): void
    {
        $source = '# `<code>`{=html}x`</code>`{=html}';
        $this->assertStringContainsString('</code></h1>' . "\n</section>\n", (new CarveConverter())->convert($source));
    }

    public function testCodeInsideAnOpaqueRawFragmentDoesNotOpenTheCounter(): void
    {
        $source = '# `<script>"<code>"</script>`{=html}x';
        $this->assertStringContainsString('</h1>' . "\n</section>\n", (new CarveConverter())->convert($source));
    }

    public function testStrippedAndEscapedCodeKeepTheSectionLayout(): void
    {
        foreach ([SafeMode::RAW_HTML_STRIP, SafeMode::RAW_HTML_ESCAPE] as $mode) {
            $safe = SafeMode::defaults()->setRawHtmlMode($mode);
            $html = (new CarveConverter(safeMode: $safe))->convert('# `<code>`{=html}x');
            $this->assertStringContainsString('</h1>' . "\n</section>\n", $html);
        }
    }

    public function testReusingTheConverterResetsOpenRawCode(): void
    {
        $converter = new CarveConverter();
        $converter->convert('# `<code>`{=html}x');
        $this->assertSame('<section id="Plain">' . "\n  " . '<h1>Plain</h1>' . "\n</section>\n", $converter->convert('# Plain'));
    }

    public function testNestedFragmentDoesNotChangeTheHeadingTagCount(): void
    {
        $renderer = new HtmlRenderer();
        $fragmentRendered = false;
        $renderer->on('render.raw_inline', static function (RenderEvent $event) use ($renderer, &$fragmentRendered): void {
            if (!$fragmentRendered) {
                $fragmentRendered = true;
                $renderer->renderInlineNodesFragment([new RawInline('<code>', 'html')]);
            }
        });
        $converter = new CarveConverter(renderer: $renderer);
        $html = $converter->convert('# `<code>`{=html}x`</code>`{=html}');
        $this->assertTrue($fragmentRendered);
        $this->assertStringContainsString('</code></h1>' . "\n</section>\n", $html);
    }

    public function testRawCodeInATableDoesNotChangeALaterHeadingSeparator(): void
    {
        $html = (new CarveConverter())->convert('| `<code>`{=html} |' . "\n\n# Plain\n");
        $this->assertStringContainsString('<h1>Plain</h1>' . "\n</section>\n", $html);
    }

    public function testCodeInsideSplitRawTextTagsDoesNotChangeTheSeparator(): void
    {
        foreach (['script', 'style', 'title', 'textarea', 'xmp'] as $tag) {
            $source = '# `<' . $tag . '>`{=html}`<code>`{=html}`</' . $tag . '>`{=html}x';
            $this->assertStringContainsString('</h1>' . "\n</section>\n", (new CarveConverter())->convert($source));
        }
    }

    public function testCodeInsideASplitCommentDoesNotChangeTheSeparator(): void
    {
        $source = '# `<!--`{=html}`<code>`{=html}`-->`{=html}x';
        $this->assertStringContainsString('</h1>' . "\n</section>\n", (new CarveConverter())->convert($source));
    }

    public function testCodeInsideASplitAttributeDoesNotChangeTheSeparator(): void
    {
        $source = '# `<span title="`{=html}`<code>`{=html}`">`{=html}x';
        $this->assertStringContainsString('</h1>' . "\n</section>\n", (new CarveConverter())->convert($source));
    }

    public function testReentrantRenderDoesNotDiscardTheHeadingTrackerReference(): void
    {
        $renderer = new HtmlRenderer();
        $nested = (new CarveConverter())->parse('# Other');
        $reentered = false;
        $renderer->on('render.raw_inline', static function (RenderEvent $event) use ($renderer, $nested, &$reentered): void {
            if (!$reentered) {
                $reentered = true;
                $renderer->render($nested);
            }
        });
        $html = (new CarveConverter(renderer: $renderer))->convert('# `<code>`{=html}x');
        $this->assertTrue($reentered);
        $this->assertStringContainsString('<h1><code>x</h1>', $html);
    }

    public function testNestedAndNonemptySectionsKeepTheirClosingSeparator(): void
    {
        foreach (["# Parent\n\n## `<code>`{=html}x", "# `<code>`{=html}x\n\nbody"] as $source) {
            $this->assertStringNotContainsString('</h1></section>', (new CarveConverter())->convert($source));
            $this->assertStringNotContainsString('</h2></section>', (new CarveConverter())->convert($source));
        }
    }

    public function testCustomHeadingOutputKeepsItsClosingSeparator(): void
    {
        $renderer = new HtmlRenderer();
        $renderer->on('render.heading', static function (RenderEvent $event): void {
            $event->setHtml('<h1><code>x</h1>' . "\n");
        });
        $html = (new CarveConverter(renderer: $renderer))->convert('# x');
        $this->assertStringContainsString('</h1>' . "\n</section>\n", $html);
    }

    public function testComplexRawScopesKeepTheirClosingSeparator(): void
    {
        foreach (['select', 'template', 'object', 'noscript', 'svg'] as $tag) {
            $source = '# `<' . $tag . '>`{=html}`<code>`{=html}x';
            $this->assertStringContainsString('</h1>' . "\n</section>\n", (new CarveConverter())->convert($source));
        }
        $source = '# `<code>`{=html}`<code>`{=html}`<code>`{=html}`<code>`{=html}x';
        $this->assertStringContainsString('</h1>' . "\n</section>\n", (new CarveConverter())->convert($source));
    }
}
