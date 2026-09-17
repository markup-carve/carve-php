<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `~>` counts as a substitution's arrow only at the pair's own level: the
 * search skips verbatim content and a delimited comment, and an escaped `~` is
 * not an arrow. A pair with no arrow of its own is a forced strikethrough
 * (markup-carve/carve#2083).
 */
class ASubstitutionArrowIsTopLevelTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function strikes(): array
    {
        return [
            'an arrow inside an unclosed run' => ["{~`a~>b~}\n", "<p><s><code>a~&gt;b</code></s></p>\n"],
            'an arrow inside a closed run' => ["{~a `x~>y` b~}\n", "<p><s>a <code>x~&gt;y</code> b</s></p>\n"],
            'an escaped arrow' => ["{~a\\~>b~}\n", "<p><s>a~&gt;b</s></p>\n"],
            'an arrow inside a longer run' => ["{~a ``x~>y`` b~}\n", "<p><s>a <code>x~&gt;y</code> b</s></p>\n"],
            'an arrow inside a comment' => ["{~a{% x~>y %}b~}\n", "<p><s>ab</s></p>\n"],
        ];
    }

    #[DataProvider('strikes')]
    public function testAPairWithNoTopLevelArrowIsAStrike(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function substitutions(): array
    {
        return [
            'a plain pair' => ["{~old~>new~}\n", "<p><del>old</del><ins>new</ins></p>\n"],
            'an arrow after a closed run' => ["{~`x`~>y~}\n", "<p><del><code>x</code></del><ins>y</ins></p>\n"],
            'an arrow after a comment' => ["{~a{% c %}~>b~}\n", "<p><del>a</del><ins>b</ins></p>\n"],
            'the first top-level arrow wins' => ["{~a~>b~>c~}\n", "<p><del>a</del><ins>b~&gt;c</ins></p>\n"],
        ];
    }

    #[DataProvider('substitutions')]
    public function testATopLevelArrowSplitsThePair(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }
}
