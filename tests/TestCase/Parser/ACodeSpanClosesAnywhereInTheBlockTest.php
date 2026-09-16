<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A backtick run with an equal-length closer anywhere later in the block is a
 * closed code span, so a forced or editorial closer inside it is code
 * (markup-carve/carve#2079). A run nothing closes still ends at that closer
 * (markup-carve/carve#2056).
 */
class ACodeSpanClosesAnywhereInTheBlockTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ruled(): array
    {
        return [
            'a strong opener the run swallows' => [
                "x{*`a*} and `b`\n",
                "<p>x{*<code>a*} and </code>b<code></code></p>\n",
            ],
            'a strong that closes after the run' => [
                "{*x`a*}`*}\n",
                "<p><strong>x<code>a*}</code></strong></p>\n",
            ],
            'an insertion opener the run swallows' => [
                "x{+`a+} and `b`\n",
                "<p>x{+<code>a+} and </code>b<code></code></p>\n",
            ],
            'an insertion that closes after the run' => [
                "{+x`a+}`+}\n",
                "<p><ins>x<code>a+}</code></ins></p>\n",
            ],
        ];
    }

    #[DataProvider('ruled')]
    public function testTheRunReachesItsCloserInTheBlock(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bounds(): array
    {
        return [
            // BOUND: a run nothing closes ends at the span's closer, which
            // corpus case 12-inline-code-7 pins.
            'an unclosed run inside a strike' => ["{~` ~}\n", "<p><s><code></code></s></p>\n"],
            'an unclosed run inside a strong' => ["{*`a*}\n", "<p><strong><code>a</code></strong></p>\n"],
            // BOUND: a closed run wholly inside the span is unchanged.
            'a closed run inside the span' => ["{*a `b` c*}\n", "<p><strong>a <code>b</code> c</strong></p>\n"],
            // BOUND: no verbatim run at all.
            'no run' => ["{*a*} b\n", "<p><strong>a</strong> b</p>\n"],
            // The longer run needs a closer of its own length.
            'a doubled run closes on a doubled run' => [
                "{*``a*}`` b\n",
                "<p>{*<code>a*}</code> b</p>\n",
            ],
        ];
    }

    #[DataProvider('bounds')]
    public function testWhatTheRuleDoesNotChange(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The bare-closer scan reads the same end for a braced span, so a bare
     * delimiter around one agrees with the span itself.
     */
    public function testTheBareCloserScanReadsTheSameEnd(): void
    {
        $this->assertSame(
            "<p><s>a{</s><code>b~}~ </code>c<code></code></p>\n",
            $this->html("~a{~`b~}~ `c`\n"),
        );
    }
}
