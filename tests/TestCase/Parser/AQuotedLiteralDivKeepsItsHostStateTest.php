<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AQuotedLiteralDivKeepsItsHostStateTest extends TestCase
{
    public function testLineBlockPayloadCreatesNoNestedClaim(): void
    {
        foreach ([1, 2] as $depth) {
            $quote = str_repeat('> ', $depth);
            $source = $quote . "- ::: |\n" . $quote . "  :::: box\n"
                . $quote . "  :::\n" . $quote . "  :::bad\n" . $quote . "  :::\n- tail\n";
            $html = (new CarveConverter())->convert($source);
            $this->assertStringContainsString('<p>:::: box</p>', $html);
            $this->assertStringNotContainsString('class="box"', $html);
            $this->assertStringEndsWith("</blockquote>\n", $html);
            $this->assertStringContainsString('tail', $html);
        }
    }

    public function testQuotedLazyDemotionRetainsTheListHost(): void
    {
        $source = "> - ::: box\n> lazy\n>   :::\n- tail\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString("<li>::: box\nlazy", $html);
        $this->assertStringContainsString("<div>\n\n", $html);
        $this->assertStringEndsWith("</blockquote>\n<ul>\n  <li>tail</li>\n</ul>\n", $html);
    }

    public function testEstablishedBodyRetainsItsCloserAcrossQuotedLazyText(): void
    {
        $source = "> - ::: box\n>   body\n> lazy\n>   :::\n- tail\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString("<div class=\"box\">\n        <p>body\nlazy</p>", $html);
        $this->assertStringEndsWith("</blockquote>\n<ul>\n  <li>tail</li>\n</ul>\n", $html);
    }

    public function testMarkerBelowAnAncestorColumnKeepsTheActualFence(): void
    {
        foreach ([1, 2] as $depth) {
            foreach ([3, 4] as $width) {
                foreach ([1, 3, 4] as $column) {
                    $quote = str_repeat('> ', $depth);
                    $fence = str_repeat(':', $width);
                    $source = $quote . "- a\n" . $quote . '  - ' . $fence . " box\n"
                        . $quote . "    body\n" . $quote . str_repeat(' ', $column) . "- next\n"
                        . $quote . '    ' . $fence . "\n- tail\n";
                    $html = (new CarveConverter())->convert($source);
                    $this->assertStringContainsString("<p>body\n- next</p>", $html);
                    $this->assertStringEndsWith("</blockquote>\n<ul>\n  <li>tail</li>\n</ul>\n", $html);
                }
            }
        }
    }

    public function testLegalSiblingColumnsEndTheOldFenceScope(): void
    {
        foreach ([0, 2] as $column) {
            $source = "> - a\n>   - ::: box\n>     body\n> " . str_repeat(' ', $column)
                . "- next\n>     :::\n- tail\n";
            $html = (new CarveConverter())->convert($source);
            $this->assertStringNotContainsString("<p>body\n- next</p>", $html);
            $this->assertStringContainsString('<li>next', $html);
            $this->assertStringEndsWith("</blockquote>\n<ul>\n  <li>tail</li>\n</ul>\n", $html);
        }
    }

    public function testMarkerBetweenThreeListHostsKeepsTheActualFence(): void
    {
        $source = "> - a\n>   - b\n>     - ::: box\n>       body\n>    - next\n>       :::\n- tail\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString("<p>body\n- next</p>", $html);
        $this->assertStringEndsWith("</blockquote>\n<ul>\n  <li>tail</li>\n</ul>\n", $html);
    }

    public function testMarkerBeforeTheOutermostHostStaysStructural(): void
    {
        $source = ">  - a\n>    - ::: box\n>      body\n> - next\n>      :::\n- tail\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString('<p>body</p>', $html);
        $this->assertStringContainsString('<li>next', $html);
        $this->assertStringNotContainsString("<p>body\n- next</p>", $html);
    }

    public function testHardbreakBodiesStillParseNestedDivs(): void
    {
        $source = <<<'CRV'
> - ::: \
>   :::: box
>     body
>   ::::
>   :::
- tail
CRV;
        $html = (new CarveConverter())->convert($source . "\n");
        $this->assertStringContainsString('class="hardbreaks"', $html);
        $this->assertStringContainsString('class="box"', $html);
        $this->assertStringContainsString('<p>body</p>', $html);
        $this->assertStringEndsWith("</blockquote>\n<ul>\n  <li>tail</li>\n</ul>\n", $html);
    }
}
