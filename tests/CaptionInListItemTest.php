<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CaptionInListItemTest extends TestCase
{
    /**
     * Captionable hosts.
     *
     * @return array<string, array{string, string}>
     */
    public static function hosts(): array
    {
        return [
            'table' => ['| a |', '<caption>cap</caption>'],
            'image' => ['![a](b.png)', '<figcaption>cap</figcaption>'],
            'quote' => ['> q', '<figcaption>cap</figcaption>'],
            'listing' => ["```\n  x\n  ```", '<figcaption>cap</figcaption>'],
            'equation' => ['$$`x`', '<figcaption>cap</figcaption>'],
            'figure group' => ["::: figure\n  ![a](b.png)\n  :::", '<figcaption>cap</figcaption>'],
        ];
    }

    #[DataProvider('hosts')]
    public function testOneBlankKeepsAttachedCaptionAndTightList(string $host, string $captionHtml): void
    {
        $html = CarveConverter::create()->convert("- {$host}\n\n  ^ cap\n- c\n");

        $this->assertStringContainsString($captionHtml, $html);
        $this->assertStringContainsString('<li>c</li>', $html);
    }

    #[DataProvider('hosts')]
    public function testTwoBlanksDetachCaptionAndLoosenList(string $host, string $captionHtml): void
    {
        $html = CarveConverter::create()->convert("- {$host}\n\n\n  ^ cap\n- c\n");

        $this->assertStringNotContainsString($captionHtml, $html);
        $this->assertStringContainsString('<p>^ cap</p>', $html);
        $this->assertStringContainsString('<li><p>c</p></li>', $html);
    }

    public function testTwoQuotedBlankLinesDetachCaption(): void
    {
        $html = CarveConverter::create()->convert("> - | a |\n>\n>\n>   ^ cap\n> - c\n");

        $this->assertStringNotContainsString('<caption>', $html);
        $this->assertStringContainsString('<p>^ cap</p>', $html);
        $this->assertStringContainsString('<li><p>c</p></li>', $html);
    }

    public function testTabAfterCaretIsNotAFigureGroupCaption(): void
    {
        $html = CarveConverter::create()->convert("- ::: figure\n  ![a](b.png)\n  :::\n\n  ^\tx\n- c\n");

        $this->assertStringNotContainsString('<figcaption>', $html);
        $this->assertStringContainsString('<li><p>c</p></li>', $html);
    }

    #[DataProvider('nonFigureGroupOpeners')]
    public function testGenericFigureDivDoesNotClaimCaption(string $opener): void
    {
        $html = CarveConverter::create()->convert("- {$opener}\n  ![a](b.png)\n  :::\n\n  ^ cap\n- c\n");

        $this->assertStringNotContainsString('<figcaption>', $html);
        $this->assertStringContainsString('<p>^ cap</p>', $html);
        $this->assertStringContainsString('<li><p>c</p></li>', $html);
    }

    /**
     * Figure divs that do not become figure groups.
     *
     * @return array<string, array{string}>
     */
    public static function nonFigureGroupOpeners(): array
    {
        return [
            'label' => ['::: figure [lbl]'],
            'attribute' => ['::: figure {#f}'],
            'title' => ['::: figure "T"'],
        ];
    }
}
