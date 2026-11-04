<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AQuoteInAQuotedItemLeavesNoParagraphAfterABlockTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function closingLines(): array
    {
        return [
            'heading' => ['# H'],
            'break' => ['---'],
            'blank' => [''],
            'div opener' => ['::: d'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function erasedLines(): array
    {
        return [
            'definition' => ['[r]: /u'],
            'indented definition' => [' [r]: /u'],
        ];
    }

    /**
     * carve#5129: an erased definition goes with its quote prefixes, so `tail` continues the paragraph.
     */
    #[DataProvider('erasedLines')]
    public function testAnErasedLineLeavesTheParagraphOpen(string $line): void
    {
        foreach (['> - > para', '> 1. > para', '> > - > para'] as $head) {
            $prefix = substr($head, 0, (int)strrpos($head, '>') + 1);
            $prefix = preg_replace('/[^> ]/', ' ', $prefix) ?? '';
            $source = $head . "\n" . rtrim($prefix . ' ' . $line) . "\ntail\n\n[r][]\n";
            $this->assertStringContainsString("<p>para\ntail</p></blockquote>", (new CarveConverter())->convert($source), $source);
        }
    }

    #[DataProvider('closingLines')]
    public function testAnUnmarkedLineLeavesEveryQuote(string $line): void
    {
        foreach (['> - > para', '> 1. > para', '> > - > para'] as $head) {
            $prefix = substr($head, 0, (int)strrpos($head, '>') + 1);
            $prefix = preg_replace('/[^> ]/', ' ', $prefix) ?? '';
            $source = $head . "\n" . rtrim($prefix . ' ' . $line) . "\ntail\n\n[r][]\n";
            $html = (new CarveConverter())->convert($source);
            $this->assertMatchesRegularExpression('/<\/blockquote>\s*<p>tail<\/p>/', $html, $source);
            $this->assertStringNotContainsString("<p>tail</p>\n</blockquote>", $html, $source);
        }
    }

    public function testAListMarkerStillFoldsIntoTheOpenParagraph(): void
    {
        $html = (new CarveConverter())->convert("> - > para\n>   > - x\ntail\n");
        $this->assertStringContainsString("<p>para\n- x\ntail</p>", $html);
    }
}
