<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class NestedHtmlIndentScanTest extends TestCase
{
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
}
