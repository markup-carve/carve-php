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
