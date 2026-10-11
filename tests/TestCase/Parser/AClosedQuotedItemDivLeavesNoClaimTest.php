<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AClosedQuotedItemDivLeavesNoClaimTest extends TestCase
{
    public function testAnUnmarkedListAfterTheCloserBelongsToTheRoot(): void
    {
        foreach ([1, 2] as $depth) {
            foreach ([3, 4] as $width) {
                foreach (['', 'body'] as $body) {
                    foreach ([0, 2] as $indent) {
                        $quote = str_repeat('> ', $depth);
                        $fence = str_repeat(':', $width);
                        $source = $quote . '- ' . $fence . " box\n"
                            . ($body === '' ? '' : $quote . '  ' . $body . "\n")
                            . $quote . '  ' . $fence . "\n"
                            . str_repeat(' ', $indent) . "- tail\n";
                        $html = (new CarveConverter())->convert($source);
                        $this->assertStringEndsWith("</blockquote>\n<ul>\n  <li>tail</li>\n</ul>\n", $html, $source);
                        $this->assertSame($depth, substr_count($html, '<blockquote>'));
                    }
                }
            }
        }
    }

    public function testAnOpenParagraphStillKeepsTheMarkerAsText(): void
    {
        $html = (new CarveConverter())->convert("> - paragraph\n- tail\n");
        $this->assertStringContainsString("<li>paragraph\n- tail</li>", $html);
        $this->assertSame(1, substr_count($html, '<ul>'));
    }

    public function testExplicitQuoteAndAttachmentMarkersKeepTheListQuoted(): void
    {
        foreach (["> - tail\n", "+\n- tail\n"] as $tail) {
            $html = (new CarveConverter())->convert("> - ::: box\n>   :::\n" . $tail);
            $this->assertStringEndsWith("</blockquote>\n", $html);
            $this->assertStringContainsString('tail', $html);
        }
    }

    public function testOpaqueCodeAndNestedDivsKeepTheOuterCloser(): void
    {
        foreach (
            [
                "> - ::: box\n>   ```\n>   :::\n>   ```\n>   :::\ntail\n",
                "> - ::: box\n>   %%%\n>   :::\n>   %%%\n>   :::\ntail\n",
                "> - ::: box\n>   - :::: inner\n>     :::: \n>   :::\ntail\n",
            ] as $source
        ) {
            $html = (new CarveConverter())->convert($source);
            $this->assertStringEndsWith("</blockquote>\n<p>tail</p>\n", $html);
        }
    }

    public function testAClaimEndsWhenItsItemOrLazyOpenerEnds(): void
    {
        foreach (
            [
                "> - ::: box\n> - b\n>   :::\ntail\n",
                "> - ::: box\ntail\n>   :::\nmore\n",
            ] as $source
        ) {
            $html = (new CarveConverter())->convert($source);
            $this->assertStringEndsWith("</blockquote>\n", $html);
        }
    }
}
