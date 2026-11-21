<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContainerPrefixTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function owners(): array
    {
        return [
            'spaces past the inner marker' => ["- a\n  - b\n\n    c\n", '<ul><li>a<ul><li>b<p>c</p></li></ul></li></ul>'],
            'spaces at the inner marker' => ["- a\n  - b\n\n  c\n", '<ul><li>a<ul><li>b</li></ul><p>c</p></li></ul>'],
            'tab past the inner marker' => ["- a\n  - b\n\n\tc\n", '<ul><li>a<ul><li>b<p>c</p></li></ul></li></ul>'],
            'space tab space at depth three' => [
                "- a\n  - b\n    - c\n\n  \t d\n",
                '<ul><li>a<ul><li>b<ul><li>c<p>d</p></li></ul></li></ul></li></ul>',
            ],
            'quote then items' => [
                "> - a\n>   - b\n>\n>     c\n",
                '<blockquote><ul><li>a<ul><li>b<p>c</p></li></ul></li></ul></blockquote>',
            ],
        ];
    }

    /**
     * C2-B-008, C2-B-027: each open container consumes its prefix from the one leading whitespace run, outside in,
     * with tabs to the next tab stop; the line belongs to the innermost container whose marker column it passes.
     */
    #[DataProvider('owners')]
    public function testEachContainerReadsItsPrefixFromTheSharedRun(string $source, string $html): void
    {
        self::assertSame($html, (string)preg_replace('/\s*\n\s*/', '', Carve::toHtml($source)));
    }
}
