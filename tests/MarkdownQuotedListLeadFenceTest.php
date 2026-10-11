<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownQuotedListLeadFenceTest extends TestCase
{
    public function testQuotedFenceOnAnItemLeadKeepsItsPayload(): void
    {
        foreach (['```', '~~~'] as $fence) {
            foreach (['- ', '1. ', '- - '] as $lead) {
                $pad = str_repeat(' ', strlen($lead));
                foreach (['', $pad . '> ' . $fence . "\n"] as $closer) {
                    $source = $lead . '> ' . $fence . "md\n" . $pad . "> x\n" . $pad . "> <!-- carve:container:open -->\n" . $closer;
                    $written = (new MarkdownToCarve())->convert($source);
                    $html = (new CarveConverter())->convert($written);
                    self::assertStringContainsString('<code class="language-md">x' . "\n" . '&lt;!-- carve:container:open --&gt;' . "\n" . '</code>', $html, $source);
                    self::assertStringNotContainsString('<p>', $html, $source);
                }
            }
        }
    }

    public function testOuterQuoteAndNestedQuoteKeepTheFence(): void
    {
        foreach (
            [
                "> - > ```md\n>   > x\n>   > ```\n> after\n",
                "- > > ```md\n  > > x\n  > > ```\n- after\n",
            ] as $source
        ) {
            $written = (new MarkdownToCarve())->convert($source);
            $html = (new CarveConverter())->convert($written);
            self::assertStringContainsString('<code class="language-md">x' . "\n" . '</code>', $html, $source);
            self::assertStringContainsString('after', $html);
        }
    }

    public function testQuoteMarkersInThePayloadStayLiteralAndASiblingStaysOutside(): void
    {
        $source = "- > ```md\n  > > x\n  > ```\n- sibling\n";
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($source));
        self::assertStringContainsString('<code class="language-md">&gt; x' . "\n" . '</code>', $html);
        self::assertStringContainsString('<li>sibling</li>', $html);
    }

    public function testIndentedCodeOnTheQuotedLeadIsCode(): void
    {
        $source = "- >     code\n  >     more\n";
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($source));
        self::assertStringContainsString('<code>code' . "\nmore\n" . '</code>', $html);
    }
}
