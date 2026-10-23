<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Extension\DetailsExtension;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Renderer\HtmlLayoutWriter;
use MarkupCarve\Carve\Renderer\HtmlLineState;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NestedHtmlIndentScanTest extends TestCase
{
    public function testAdjacentHighlightedBlocksKeepTheirCodeWhitespaceInSections(): void
    {
        $converter = new CarveConverter();
        $converter->on('render.code_block', static function (RenderEvent $event): void {
            $code = htmlspecialchars($event->getNode()->getContent(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $event->setHtml('<pre><code><span>' . $code . '</span></code></pre>');
            $event->preventDefault();
        });

        $html = $converter->convert("# Examples\n\n```carve\none\ntwo\n```\n```carve\nthree\nfour\n```\n");

        $this->assertStringContainsString("<span>one\ntwo\n</span>", $html);
        $this->assertStringContainsString("<span>three\nfour\n</span>", $html);
        $this->assertStringNotContainsString("three\n  four", $html);
    }

    public function testAdjacentPreTagsKeepTheWriterInPreformattedMode(): void
    {
        $writer = new HtmlLayoutWriter();
        $writer->pushIndent(2);
        $writer->write("<pre><code>one\n</code></pre><pre><code>two\nthree\n</code></pre>\n<p>after</p>");
        $writer->popIndent();

        $this->assertSame(
            "  <pre><code>one\n</code></pre><pre><code>two\nthree\n</code></pre>\n  <p>after</p>",
            $writer->finish(),
        );
    }

    public function testAClosingPreFollowedByAnotherTagLeavesPreformattedMode(): void
    {
        $this->assertFalse(HtmlLineState::endsInsidePre('</pre><preview>', true));
        $this->assertTrue(HtmlLineState::endsInsidePre('</pre><pre class="x">', true));
    }

    public function testAdjacentHighlightedBlocksKeepTheirCodeWhitespaceInDisclosures(): void
    {
        foreach (['details' => new DetailsExtension(), 'spoiler' => new SpoilerExtension()] as $name => $extension) {
            $converter = new CarveConverter();
            $converter->addExtension($extension);
            $converter->on('render.code_block', static function (RenderEvent $event): void {
                $event->setHtml('<pre><code><span>' . $event->getNode()->getContent() . '</span></code></pre>');
                $event->preventDefault();
            });

            $source = "::: $name\n```\none\n```\n```\nthree\nfour\n```\n:::\n";
            $html = $converter->convert($source);

            $this->assertStringContainsString("<span>three\nfour\n</span>", $html);
        }
    }

    public function testIndentPreservesTagContinuationsAndPreformattedPayloads(): void
    {
        $renderer = new class extends HtmlRenderer {
            public function indent(string $html): string
            {
                return $this->indentBlock($html, 2);
            }
        };
        $source = "<div title=\"first\nsecond\">\n<pre><code>one\n  two\n</code></pre>\n<p>end</p>\n</div>";
        $expected = "  <div title=\"first\nsecond\">\n  <pre><code>one\n  two\n</code></pre>\n  <p>end</p>\n  </div>";
        $this->assertSame($expected, $renderer->indent($source));
        $this->assertSame("  <a>x</a><b\nvalue>\n  end", $renderer->indent("<a>x</a><b\nvalue>\nend"));
        $this->assertSame("  x <é y\n  a<\n  end", $renderer->indent("x <é y\na<\nend"));
        $this->assertSame("  <a\n\"><b\nvalue>\n  end", $renderer->indent("<a\n\"><b\nvalue>\nend"));
        $this->assertSame("  <3\n  <\n  </div\n>\n  text", $renderer->indent("<3\n<\n</div\n>\ntext"));
    }

    /**
     * Each case crosses a boundary between the bulk-padded runs and the
     * per-line walk; the expected strings are the per-line walk's output.
     */
    #[DataProvider('runBoundaryProvider')]
    public function testIndentMatchesTheLineWalkAcrossRunBoundaries(string $html, string $expected): void
    {
        $renderer = new class extends HtmlRenderer {
            public function indent(string $html): string
            {
                return $this->indentBlock($html, 2);
            }
        };

        $this->assertSame($expected, $renderer->indent($html));
    }

    public function testAZeroWidthIndentReturnsTheInputUnchanged(): void
    {
        $renderer = new class extends HtmlRenderer {
            public function indent(string $html): string
            {
                return $this->indentBlock($html, 0);
            }
        };

        $this->assertSame("<p>a</p>\n<p>b</p>\n", $renderer->indent("<p>a</p>\n<p>b</p>\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function runBoundaryProvider(): array
    {
        return [
            'empty' => ['', ''],
            'trailing newline' => ["<p>a</p>\n", "  <p>a</p>\n"],
            'blank lines' => ["<p>a</p>\n\n\n<p>b</p>", "  <p>a</p>\n\n\n  <p>b</p>"],
            'leading blank line' => ["\n<p>a</p>", "\n  <p>a</p>"],
            'carriage return is content' => ["<p>a</p>\r\n\r\n<p>b</p>", "  <p>a</p>\r\n  \r\n  <p>b</p>"],
            'one-line pre' => ["<pre><code>x</code></pre>\n<p>a</p>", "  <pre><code>x</code></pre>\n  <p>a</p>"],
            'pre never closed' => ["<p>a</p>\n<pre><code>x\ny\nz", "  <p>a</p>\n  <pre><code>x\ny\nz"],
            'closer line opens the next pre' => [
                "<pre><code>a\n</code></pre><pre><code>b\nc\n</code></pre>\n<p>d</p>",
                "  <pre><code>a\n</code></pre><pre><code>b\nc\n</code></pre>\n  <p>d</p>",
            ],
            'one-line pre opens another pre' => [
                "<pre><code>a</code></pre><pre><code>b\nc\n</code></pre>\n<p>d</p>",
                "  <pre><code>a</code></pre><pre><code>b\nc\n</code></pre>\n  <p>d</p>",
            ],
            'tag opened on the pre line' => [
                "<pre title=\"a\nb\"><code>x\n</code></pre>\n<p>c</p>",
                "  <pre title=\"a\nb\"><code>x\n</code></pre>\n<p>c</p>",
            ],
            'multi-line tag inside pre' => [
                "<pre><code><b\nc\n</code></pre>\n<p>d</p>",
                "  <pre><code><b\nc\n</code></pre>\n<p>d</p>",
            ],
            'multi-line tag after pre' => [
                "<pre><code>x\n</code></pre>\n<img alt=\"a\nb\">\n<p>c</p>",
                "  <pre><code>x\n</code></pre>\n  <img alt=\"a\nb\">\n  <p>c</p>",
            ],
            'unclosed tag on the last line' => ["<p>a</p>\n<img alt=\"b", "  <p>a</p>\n  <img alt=\"b"],
            'escaped text and stray brackets' => ["a &lt;b\n<3 >\nx > y\n<p>z</p>", "  a &lt;b\n  <3 >\n  x > y\n  <p>z</p>"],
        ];
    }

    #[DataProvider('nestedDocumentProvider')]
    public function testNestedContainersKeepTheirRenderedLayout(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nestedDocumentProvider(): array
    {
        return [
            'code block with a blank line in a nested item' => [
                "- a\n\n  - b\n\n    ```\n    x\n\n      y\n    ```\n\n  - c\n",
                "<ul>\n  <li>a\n    <ul>\n      <li><p>b</p>\n        <pre><code>x\n\n  y\n</code></pre>\n      </li>\n"
                . "      <li><p>c</p></li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'multi-line alt text in a nested item' => [
                "- a\n  - ![one\n    two](/i.png)\n\n    text\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <img src=\"/i.png\" alt=\"one\ntwo\">\n        <p>text</p>\n"
                . "      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'task items and a mixed ordered sub-list' => [
                "- [ ] open\n  - [x] done\n\n    more\n- plain\n  1. one\n  2. two\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"open\"> open\n    <ul class=\"task-list\">\n"
                . "      <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled aria-label=\"done\"> <p>done</p>\n        <p>more</p>\n"
                . "      </li>\n    </ul>\n  </li>\n</ul>\n<ul>\n  <li>plain\n    <ol>\n      <li>one</li>\n"
                . "      <li>two</li>\n    </ol>\n  </li>\n</ul>\n",
            ],
            'loose item around a tight sub-list with a soft break' => [
                "- a\n\n  b\n\n  - c\n    d\n\n- e\n",
                "<ul>\n  <li><p>a</p>\n    <p>b</p>\n    <ul>\n      <li>c\nd</li>\n    </ul>\n  </li>\n"
                . "  <li><p>e</p></li>\n</ul>\n",
            ],
            'raw block with a multi-line tag and a blank line' => [
                "- a\n  - ``` =html\n    <div\n    class=\"x\">\n\n    </div>\n    ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <div\nclass=\"x\">\n\n</div>\n      </li>\n"
                . "    </ul>\n  </li>\n</ul>\n",
            ],
        ];
    }

    public function testAnOverriddenItemIsIndentedByTheList(): void
    {
        $renderer = new class extends HtmlRenderer {
            protected function renderListItem(ListItem $node, bool $tight = true): string
            {
                return "<li data-x=\"a\nb\">\n<pre><code>k\n</code></pre>\n</li>";
            }
        };
        $document = (new CarveConverter())->parse("- a\n  - b\n");

        $this->assertSame(
            "<ul>\n  <li data-x=\"a\nb\">\n  <pre><code>k\n</code></pre>\n  </li>\n</ul>\n",
            $renderer->render($document),
        );
    }
}
