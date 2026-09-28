<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AQuotedItemFenceDoesNotKeepAnUnmarkedLineTest extends TestCase
{
    /**
     * CARVE-P0-014: code stores no paragraph continuation claim, at any opener column.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function fences(): iterable
    {
        foreach ([1, 2, 3] as $depth) {
            $quote = str_repeat('> ', $depth);
            $indent = str_repeat('  ', $depth);
            foreach (['```', '~~~', '```=html', '~~~=html'] as $opener) {
                foreach ([0, 1, 2, 4] as $extra) {
                    $column = str_repeat(' ', 2 + $extra);
                    $source = "{$quote}- a\n{$quote}\n{$quote}{$column}{$opener}\n{$quote}{$column}a\n";
                    $block = str_ends_with($opener, '=html') ? "a\n" : "<pre><code>a\n</code></pre>\n";
                    $expected = '';
                    for ($i = 0; $i < $depth; $i++) {
                        $expected .= str_repeat('  ', $i) . "<blockquote>\n";
                    }
                    $expected .= "{$indent}<ul>\n{$indent}  <li>a\n{$indent}    {$block}"
                        . "{$indent}  </li>\n{$indent}</ul>\n";
                    for ($i = $depth - 1; $i >= 0; $i--) {
                        $expected .= str_repeat('  ', $i) . "</blockquote>\n";
                    }
                    $expected .= "<p>flush</p>\n";

                    yield "depth {$depth} {$opener} +{$extra} unfinished" => [$source . "flush\n", $expected];
                    yield "depth {$depth} {$opener} +{$extra} closed" => [
                        $source . $quote . $column . substr($opener, 0, 3) . "\nflush\n",
                        $expected,
                    ];
                }
            }
        }
    }

    #[DataProvider('fences')]
    public function testUnmarkedLineBelongsToTheDocument(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    #[DataProvider('fences')]
    public function testLaterQuotedLinesStartASeparateQuote(string $source, string $expected): void
    {
        $tail = ">     b\n>     ```\n";
        $converter = new CarveConverter();
        $this->assertSame($expected . $converter->convert($tail), $converter->convert($source . $tail));
    }

    public function testAnUnterminatedRunInAParagraphStaysLazy(): void
    {
        $this->assertSame(
            "<blockquote>\n  <ul>\n    <li>a\n<code>\npayload\nflush</code></li>\n  </ul>\n</blockquote>\n",
            (new CarveConverter())->convert("> - a\n>     ```\n>     payload\nflush\n"),
        );
    }

    public function testACloserPastTheOpenerRemainsPayload(): void
    {
        $this->assertSame(
            "<blockquote>\n  <ul>\n    <li>a\n      <pre><code>payload\n ```\nparagraph\n</code></pre>\n"
                . "    </li>\n  </ul>\n</blockquote>\n<p>flush</p>\n",
            (new CarveConverter())->convert(
                "> - a\n>\n>     ```\n>     payload\n>      ```\n>   paragraph\nflush\n",
            ),
        );
    }

    public function testACloserInTheBandAllowsANewParagraph(): void
    {
        $this->assertSame(
            "<blockquote>\n  <ul>\n    <li>a\n      <pre><code>payload\n</code></pre>\n      paragraph\n"
                . "    </li>\n  </ul>\n  <p>flush</p>\n</blockquote>\n",
            (new CarveConverter())->convert(
                "> - a\n>\n>     ```\n>     payload\n>   ```\n>   paragraph\nflush\n",
            ),
        );
    }

    public function testPayloadBelowTheOpenerKeepsTheFenceOpen(): void
    {
        $source = "> - a\n>\n>     ```\n>   payload\nflush\n";
        $this->assertSame(
            "<blockquote>\n  <ul>\n    <li>a\n      <pre><code>payload\n</code></pre>\n"
                . "    </li>\n  </ul>\n</blockquote>\n<p>flush</p>\n",
            (new CarveConverter())->convert($source),
        );
    }
}
