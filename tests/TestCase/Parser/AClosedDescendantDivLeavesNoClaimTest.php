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

    public function testLegalSiblingsRemainSeparateBeforeTheFollowingEmptyDiv(): void
    {
        foreach ([0, 2] as $column) {
            $source = "- a\n  - ::: box\n    body\n" . str_repeat(' ', $column)
                . "- sibling\n    :::\ntail\n";
            $html = (new CarveConverter())->convert($source);
            $this->assertStringContainsString('<p>body</p>', $html);
            $this->assertStringContainsString('<li>sibling', $html);
            $this->assertStringNotContainsString("<p>body\n- sibling</p>", $html);
            $this->assertStringEndsWith("</ul>\n<p>tail</p>\n", $html);
        }
    }

    public function testCollapsedMarkersAndOpaquePayloadKeepTheRealCloser(): void
    {
        foreach (["- - ::: box\n      body\n      :::", "- ::: box\n    ```\n    :::\n    ```\n    :::"] as $body) {
            $html = (new CarveConverter())->convert("- a\n  " . $body . "\ntail\n");
            $this->assertStringContainsString('<div class="box">', $html);
            $this->assertStringEndsWith("</ul>\n<p>tail</p>\n", $html);
        }
    }

    public function testAnIndentedParagraphAfterTheCloserHasItsOwnClaim(): void
    {
        $html = (new CarveConverter())->convert("- a\n  - ::: box\n    body\n    :::\n    after\ntail\n");
        $this->assertStringContainsString("after\ntail", $html);
        $this->assertStringEndsWith("</ul>\n", $html);
    }

    public function testTheNewSiblingTracksItsOwnClosedOrOpenDiv(): void
    {
        foreach ([false, true] as $closed) {
            $source = "- a\n  - ::: box\n    body\n  - sibling\n    :::\n    inner\n"
                . ($closed ? "    :::\n" : '') . "tail\n";
            $html = (new CarveConverter())->convert($source);
            $this->assertStringContainsString('<li>sibling', $html);
            if ($closed) {
                $this->assertStringContainsString('<p>inner</p>', $html);
                $this->assertStringEndsWith("</ul>\n<p>tail</p>\n", $html);
            } else {
                $this->assertStringContainsString("<p>inner\ntail</p>", $html);
                $this->assertStringEndsWith("</ul>\n", $html);
            }
        }
    }

    public function testATableSiblingLeavesNoParagraphClaim(): void
    {
        $html = (new CarveConverter())->convert("- a\n  - ::: box\n    body\n  - | x | y |\ntail\n");
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringEndsWith("</ul>\n<p>tail</p>\n", $html);
    }

    public function testAnUnestablishedOpenerStillDemotes(): void
    {
        $html = (new CarveConverter())->convert("- a\n  - ::: box\nlazy\n");
        $this->assertStringNotContainsString('class="box"', $html);
        $this->assertStringContainsString('::: box', $html);
        $this->assertStringContainsString('lazy', $html);
    }
}
