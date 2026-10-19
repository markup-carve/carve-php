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
     * @return array<string, array{string}>
     */
    public static function hosts(): array
    {
        return [
            'table' => ['| a |'],
            'image' => ['![a](b.png)'],
            'quote' => ['> q'],
            'listing' => ["```\n  x\n  ```"],
            'equation' => ['$$`x`'],
            'figure group' => ["::: figure\n  ![a](b.png)\n  :::"],
        ];
    }

    #[DataProvider('hosts')]
    public function testOneBlankKeepsAttachedCaptionAndTightList(string $host): void
    {
        $html = CarveConverter::create()->convert("- {$host}\n\n  ^ cap\n- c\n");

        $this->assertStringContainsString('>cap</', $html);
        $this->assertStringContainsString('<li>c</li>', $html);
    }

    public function testTwoBlanksDetachTableCaptionAndLoosenList(): void
    {
        $html = CarveConverter::create()->convert("- | a |\n\n\n  ^ cap\n- c\n");

        $this->assertStringNotContainsString('<caption>', $html);
        $this->assertStringContainsString('<p>^ cap</p>', $html);
        $this->assertStringContainsString('<li><p>c</p></li>', $html);
    }

    public function testTwoBlanksDetachFigureGroupCaption(): void
    {
        $html = CarveConverter::create()->convert("- ::: figure\n  ![a](b.png)\n  :::\n\n\n  ^ cap\n- c\n");

        $this->assertStringNotContainsString('<figcaption>', $html);
        $this->assertStringContainsString('<p>^ cap</p>', $html);
        $this->assertStringContainsString('<li><p>c</p></li>', $html);
    }
}
