<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Resolver;
use PHPUnit\Framework\TestCase;

final class ResolvedTableGridTest extends TestCase
{
    public function testResolvedGridAndFreshGridRenderTheSameSpan(): void
    {
        // C2-B-172, corpus CC-120: the span derived during resolution is used by C2-H-056 rendering.
        $source = "| a |{rowspan=5 .x} b |\n| c | ^ |";
        $expected = "<table>\n  <tbody>\n    <tr><td>a</td><td rowspan=\"2\" class=\"x\">b</td></tr>\n"
            . "    <tr><td>c</td></tr>\n  </tbody>\n</table>";
        $doc = Carve::parse($source);
        $opts = Options::from([]);
        $facts = Resolver::resolve($doc, $source, $opts, true)->facts;

        self::assertSame($expected, HtmlRenderer::render($doc, $opts, $source, $facts));
        self::assertSame($expected, HtmlRenderer::render($doc, $opts, $source));
    }
}
