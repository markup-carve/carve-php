<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
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
}
