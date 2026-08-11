<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

class DifferentialAuditRegressionTest extends TestCase
{
    public function testWhitespaceOnlySingleCellRowInsideAListStaysLiteral(): void
    {
        $html = (new CarveConverter())->convert("* | |\n");
        $this->assertStringContainsString('<li>| |</li>', $html);
    }

    public function testIndentedLineAfterAbbreviationDefinitionIsAParagraph(): void
    {
        $html = (new CarveConverter())->convert("*[A]: r\n b\n");
        $this->assertSame("<p>b</p>\n", $html);
    }

    public function testContentlessBulletDoesNotHideFollowingAbbreviationDefinition(): void
    {
        $html = (new CarveConverter())->convert("* \n*[A]: r\nA\n");
        $this->assertStringContainsString('<abbr title="r">A</abbr>', $html);
    }

    public function testInlineMathMayRunUnclosedToTheEndOfTheBlock(): void
    {
        $html = (new CarveConverter())->convert("$`x\n");
        $this->assertSame("<p><span class=\"math inline\">\\(x\\)</span></p>\n", $html);
    }

    public function testInlineFootnoteContributesNothingToAHeadingId(): void
    {
        $html = (new CarveConverter())->convert("# ^[n]\n");
        $this->assertStringContainsString('<section id="s">', $html);
    }

    public function testMarkerSpaceMayBeFollowedByTabPadding(): void
    {
        $this->assertStringContainsString('<li>b</li>', (new CarveConverter())->convert(". \tb\n"));
    }

    public function testResidualQuoteIndentKeepsAThematicRunInTheParagraph(): void
    {
        $html = (new CarveConverter())->convert(">  ---\nh\n");
        $this->assertSame("<blockquote><p>—\nh</p></blockquote>\n", $html);
    }

    public function testMarkerLineTableKeepsTheFollowingItemContent(): void
    {
        $html = (new CarveConverter())->convert("- |b|\nb\n");
        $this->assertStringContainsString("</table>\n    b\n  </li>", $html);
    }

    public function testInvalidFenceInfoDoesNotInterruptAParagraph(): void
    {
        $html = (new CarveConverter())->convert("{ \n~~~%\n~~~\n");
        $this->assertSame("<p>{\n~~~%\n~~~</p>\n", $html);
    }

    public function testTagNodeSurvivesInsideAHeadingDerivedLinkLabel(): void
    {
        $html = (new CarveConverter())->convert("# a &#65; b\n\n[a &#65; b][]\n");
        $this->assertStringContainsString(
            '<a href="#a-65-b">a &amp;<span class="tag"><strong>#65</strong></span>; b</a>',
            $html,
        );
    }

    public function testImageAltTextContributesToAHeadingKeyWithoutMakingTheImageBlockLevel(): void
    {
        $html = (new CarveConverter())->convert("# a ![alt](/i.png) b\n\n[a ![alt](/i.png) b][]\n");
        $this->assertSame(
            "<section id=\"a-alt-b\">\n"
            . "  <h1>a <img src=\"/i.png\" alt=\"alt\"> b</h1>\n"
            . "  <p><a href=\"#a-alt-b\">a <img src=\"/i.png\" alt=\"alt\"> b</a></p>\n"
            . "</section>\n",
            $html,
        );
    }

    public function testImageInsideAHeadingStaysInlineOnTextTargets(): void
    {
        $source = "# a ![alt](/i.png) b\n\n[a ![alt](/i.png) b][]\n";

        $plain = (new CarveConverter(renderer: new PlainTextRenderer()))->convert($source);
        $this->assertSame("a alt b\n\na alt b\n", $plain);

        $ansi = (new CarveConverter(renderer: new AnsiRenderer(useColors: false)))->convert($source);
        $this->assertStringContainsString("a [img: alt] b\n", $ansi);
        $this->assertStringNotContainsString("[img: alt]\n\n b", $ansi);
    }
}
