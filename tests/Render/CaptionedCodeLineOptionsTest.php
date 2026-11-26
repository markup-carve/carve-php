<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-063, C2-H-206, C2-H-211 (carve#6791): the line options of a captioned code block stay on its `<pre>`.
 */
final class CaptionedCodeLineOptionsTest extends TestCase
{
    public function testKeyNamesMatchWithoutCaseAndOnlyTheLowerCaseClassMoves(): void
    {
        $this->assertSame(
            "<figure class=\"LINE-NUMBERS\">\n"
            . "  <pre Data-Highlight=\"2\"><code>x\n</code></pre>\n"
            . "  <figcaption>c</figcaption>\n"
            . '</figure>',
            Carve::toHtml("{.LINE-NUMBERS Data-Highlight=2}\n```\nx\n```\n^ c\n"),
        );
    }

    public function testFigureGroupPanelKeepsTheLineOptionsOnPre(): void
    {
        $html = Carve::toHtml(
            "::: figure\n{.wide .line-numbers}\n```\na\n```\n^ p1\n\n```\nb\n```\n^ p2\n:::\n^ g\n",
        );
        $this->assertStringContainsString(
            "<figure class=\"carve-figure-panel wide\">\n    <pre class=\"line-numbers\"><code>a\n",
            $html,
        );
    }

    public function testCodeBlockRendererReceivesOnlyTheLineOptions(): void
    {
        $seen = null;
        Carve::toHtml("{#f .wide .line-numbers data-x=y data-line-start=3}\n```ts\nx\n```\n^ cap\n", [
            'codeBlockRenderer' => static function (Node $node, string $default) use (&$seen): ?string {
                $seen = $node->f['attrs'] ?? null;

                return null;
            },
        ]);
        $this->assertInstanceOf(Attrs::class, $seen);
        $this->assertNull($seen->id);
        $this->assertSame(['line-numbers'], $seen->classes);
        $this->assertSame([['data-line-start', '3']], $seen->pairs);
    }

    public function testCaptionedBlockWithoutLineOptionsIsUnchanged(): void
    {
        $this->assertSame(
            "<figure id=\"c\" class=\"wide\">\n"
            . "  <pre><code>x\n</code></pre>\n"
            . "  <figcaption>c</figcaption>\n"
            . '</figure>',
            Carve::toHtml("{#c .wide}\n```\nx\n```\n^ c\n"),
        );
    }
}
