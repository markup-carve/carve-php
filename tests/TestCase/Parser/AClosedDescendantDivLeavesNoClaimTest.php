<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Test\PinnedOracleHtml;
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

    public function testADeepMalformedFenceKeepsItsParagraphClaim(): void
    {
        $html = (new CarveConverter())->convert("- a\n  - b\n    - c\n      :::note\n      :::\ntail\n");
        $this->assertStringContainsString("<li>c\n:::note\n:::\ntail</li>", $html);
        $this->assertStringNotContainsString('<div>', $html);
        $this->assertStringEndsWith("</ul>\n", $html);
    }

    public function testTheDescendantClaimStateSurvivesAClosedDiv(): void
    {
        $parser = new BlockParser();
        $html = (new HtmlRenderer())->render($parser->parse("- a\n  - ::: box\n    body\n - next\n    :::\ntail\n"));
        $this->assertStringContainsString("<p>body\n- next</p>", $html);
        $this->assertStringEndsWith("</ul>\n<p>tail</p>\n", $html);
    }

    public function testAnAttachedListKeepsItsDescendantDivBody(): void
    {
        $html = (new CarveConverter())->convert("> q\n+\n- b\n  ::: note\n  x\n  :::\n");
        $this->assertStringContainsString('<p>x</p>', $html);
        $this->assertStringContainsString('<aside class="admonition note"', $html);
        $this->assertStringEndsWith("</blockquote>\n", $html);
    }

    public function testAQuoteBeforeTheDivKeepsTheBlankInTheItemBody(): void
    {
        $source = "- a\n  > # H\n    ::: note\n    x\n\n    y\n    :::\ntail\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString("<p>x</p>\n      <p>y</p>", $html);
        $this->assertStringNotContainsString('<li><p>a</p>', $html);
        $this->assertStringEndsWith("</ul>\n<p>tail</p>\n", $html);
    }

    public function testABlankInsideTheRealDescendantDivKeepsBothParagraphs(): void
    {
        $html = (new CarveConverter())->convert("- a\n  - ::: box\n    body\n\n    more\n    :::\ntail\n");
        $this->assertStringContainsString("<p>body</p>\n          <p>more</p>", $html);
        $this->assertStringEndsWith("</ul>\n<p>tail</p>\n", $html);
    }

    public function testAMarkerLineOpenerLeavesNoLazyClaim(): void
    {
        $source = "- a\n  - ::: box\nlazy\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertSame(trim(PinnedOracleHtml::forSource($source)), trim($html));
    }

    public function testAnEmptyContinuationDivLeavesNoClaim(): void
    {
        $html = (new CarveConverter())->convert("- a\n  - b\n    ::: box\nlazy\n");
        $this->assertStringEndsWith("</ul>\n<p>lazy</p>\n", $html);
    }

    public function testNestedCodePayloadKeepsWhitespaceOnlyLines(): void
    {
        $html = (new CarveConverter())->convert("- a\n  - ::: box\n    ```\n    x\n      \n    y\n    ```\n    :::\n");
        $this->assertStringContainsString("<pre><code>x\n  \ny\n</code></pre>", $html);
    }

    public function testDefinitionLookingPayloadIsNotHoistedFromANote(): void
    {
        $source = "[^n]: note\n  - b\n    ::: box\n    ```\n    [r]: /url\n    ```\n    :::\n\nref[^n]\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString("<p>note\n- b</p>", $html);
        $this->assertStringContainsString("<div class=\"box\">\n        <pre><code>[r]: /url\n</code></pre>", $html);
    }

    public function testEmptyOrTableEndingContinuationDivsLeaveNoClaim(): void
    {
        foreach ([":::\nlazy", "::: box\n    | x | y |\nlazy"] as $body) {
            $html = (new CarveConverter())->convert("- a\n  - b\n    " . $body . "\n");
            $this->assertStringEndsWith("</ul>\n<p>lazy</p>\n", $html);
        }
    }

    public function testMarkerLineDivDoesNotShieldANoteDefinition(): void
    {
        $html = (new CarveConverter())->convert("[^n]: note\n  - ::: box\n    [r]: /url\n    :::\n\nref[^n]\n");
        $this->assertStringNotContainsString('[r]: /url', $html);
        $this->assertStringContainsString("<p>note\n- ::: box</p>", $html);
    }
}
