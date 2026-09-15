<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §9 E2a: a bare delimiter never pairs across a code span, raw inline,
 * inline math or braced inline. Every other construct is transparent to it
 * (markup-carve/carve#2027).
 */
class ABareCloserSkipsOnlyTheConstructsE2aNamesTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function opaqueProvider(): array
    {
        return [
            'the clause example' => ["~{/x/}{/y~/}\n", "<p>~<em>x</em><em>y~</em></p>\n"],
            'a forced span across a newline' => ["~{/a\nb~/}\n", "<p>~<em>a\nb~</em></p>\n"],
            'a forced span of another marker across a newline' => ["_a{*b\nc_*} d_\n", "<p><u>a<strong>b\nc_</strong> d</u></p>\n"],
            'a highlight across a newline' => ["~a{=b\nc~=} d~\n", "<p><s>a<mark>b\nc~</mark> d</s></p>\n"],
            'a superscript across a newline' => ["~a{^b\nc~^} d~\n", "<p><s>a<sup>b\nc~</sup> d</s></p>\n"],
            'a subscript across a newline' => ["~a{,b\nc~,} d~\n", "<p><s>a<sub>b\nc~</sub> d</s></p>\n"],
            'an underline across a newline' => ["~a{_b\nc~_} d~\n", "<p><s>a<u>b\nc~</u> d</s></p>\n"],
            'an insertion across a newline' => ["~a{+b\nc~+} d~\n", "<p><s>a<ins>b\nc~</ins> d</s></p>\n"],
            'a deletion across a newline' => ["~a{-b\nc~-} d~\n", "<p><s>a<del>b\nc~</del> d</s></p>\n"],
            'a substitution across a newline' => ["~a{~b\nx~>c~~} d~\n", "<p><s>a<del>b\nx</del><ins>c~</ins> d</s></p>\n"],
            'an editorial comment across a newline' => ["~a{#b\nc~#} d~\n", "<p><s>a<span class=\"critic-comment\">b\nc~</span> d</s></p>\n"],
            'a code span' => ["~`a~b`~\n", "<p><s><code>a~b</code></s></p>\n"],
            'a raw inline format is not a braced highlight' => ["=a `b`{=html} c= d=}\n", "<p><mark>a b c</mark> d=}</p>\n"],
        ];
    }

    #[DataProvider('opaqueProvider')]
    public function testABareCloserDoesNotReachInside(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * @return array<string, array<string>>
     */
    public static function transparentProvider(): array
    {
        return [
            'a link destination' => ["~[a](b~) c\n", "<p><s>[a](b</s>) c</p>\n"],
            'an autolink' => ["~<http://x/a~>\n", "<p><s>&lt;http://x/a</s>&gt;</p>\n"],
            'a plain brace group' => ["~a{b~}c\n", "<p><s>a{b</s>}c</p>\n"],
            'an attribute block' => ["~a{.c~} d\n", "<p><s>a{.c</s>} d</p>\n"],
            'an attribute block after a braced inline' => ["~{/a/}{.c~} d~\n", "<p><s><em>a</em>{.c</s>} d~</p>\n"],
            'an empty brace pair' => ["~a{~~}b~\n", "<p><s>a{</s>~}b~</p>\n"],
        ];
    }

    #[DataProvider('transparentProvider')]
    public function testABareCloserClosesInside(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }
}
