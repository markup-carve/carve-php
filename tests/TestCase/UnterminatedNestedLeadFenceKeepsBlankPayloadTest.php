<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnterminatedNestedLeadFenceKeepsBlankPayloadTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function whitespacePayloadProvider(): array
    {
        return [
            'nested blank after opener' => ["- - ```\n      \n\ntail", "  \n\n"],
            'nested blank after text' => ["- - ```\n    x\n      \n\ntail", "x\n  \n\n"],
            'third depth blank' => ["- - - ```\n        \n\ntail", "  \n\n"],
        ];
    }

    #[DataProvider('whitespacePayloadProvider')]
    public function testWhitespacePayload(string $source, string $expectedPayload): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertSame(1, preg_match('~<pre><code>(.*?)</code></pre>~s', $html, $matches));
        self::assertSame($expectedPayload, $matches[1]);
    }

    public function testNestedLeadKeepsItsBlankBeforeTail(): void
    {
        self::assertSame(
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n",
            (new CarveConverter())->convert("- - ```\n\ntail"),
        );
    }

    public function testClosedNestedFenceKeepsItsBlank(): void
    {
        self::assertSame(
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n",
            (new CarveConverter())->convert("- - ```\n\n    ```\ntail"),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blankPayloadProvider(): array
    {
        $cases = [];
        foreach ([1, 2, 3] as $depth) {
            foreach (['tail', '[^1]: a', '[r]: /u', '{.x}'] as $follower) {
                foreach (['', "\n"] as $finalBreak) {
                    foreach ([1, 2] as $blanks) {
                        $source = str_repeat('- ', $depth) . '```' . str_repeat("\n", $blanks + 1) . $follower . $finalBreak;
                        $cases["depth $depth, $follower, final " . strlen($finalBreak) . ", blanks $blanks"] = [$source, str_repeat("\n", $blanks)];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('blankPayloadProvider')]
    public function testBlankPayload(string $source, string $expectedPayload): void
    {
        $html = (new CarveConverter())->convert($source);
        if (preg_match('~<pre><code>(.*?)</code></pre>~s', $html, $matches) !== 1) {
            self::fail('Expected a fenced code block in the rendered HTML.');
        }
        self::assertSame($expectedPayload, $matches[1]);
    }
}
