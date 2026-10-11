<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AClosedDescendantDivLeavesNoClaimTest extends TestCase
{
    public function testAListBeforeTheOldQuoteHostRegistersItsClosedDiv(): void
    {
        $html = (new CarveConverter())->convert(">  - a\n> - next\n>   ::: b\n>   x\n>   :::\ntail\n");
        $this->assertStringContainsString('<div class="b">', $html);
        $this->assertStringContainsString('<p>x</p>', $html);
        $this->assertStringEndsWith("</blockquote>\n<p>tail</p>\n", $html);
    }

    public function testTheOnlyQuoteItemRetainsItsLazyMarker(): void
    {
        $html = (new CarveConverter())->convert("> - ::: box\n>   body\n>  - next\n>   :::\ntail\n");
        $this->assertStringContainsString("<p>body\n- next</p>", $html);
        $this->assertStringEndsWith("</blockquote>\n<p>tail</p>\n", $html);
    }

    public function testAnUnquotedDescendantClosesAfterItsLazyBody(): void
    {
        foreach (['', " - next\n"] as $lazy) {
            $html = (new CarveConverter())->convert("- a\n  - ::: box\n    body\n" . $lazy . "    :::\ntail\n");
            $this->assertStringContainsString('<div class="box">', $html);
            $this->assertStringContainsString($lazy === '' ? '<p>body</p>' : "<p>body\n- next</p>", $html);
            $this->assertStringEndsWith("</ul>\n<p>tail</p>\n", $html);
        }
    }

    public function testAnUnestablishedOpenerStillDemotes(): void
    {
        $html = (new CarveConverter())->convert("- a\n  - ::: box\nlazy\n");
        $this->assertStringNotContainsString('class="box"', $html);
        $this->assertStringContainsString('::: box', $html);
        $this->assertStringContainsString('lazy', $html);
    }
}
