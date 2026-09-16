<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bracketed label escapes the brackets of its TEXT, not of the Carve its
 * children already wrote, and alt text is written raw where the run closes
 * (markup-carve/carve-php#2056).
 */
class ALabelAndAnAltImportWithoutAddedEscapesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'a code span in a link label' => [
                '<p><a href="u"><code>[a]</code></a></p>',
                "[`[a]`](u)\n",
                '<p><a href="u"><code>[a]</code></a></p>',
            ],
            'a code span in a destination-less anchor' => [
                '<p><a href="" class="k"><code>[a]</code></a></p>',
                "[`[a]`]{.k}\n",
                '<p><span class="k"><code>[a]</code></span></p>',
            ],
            'an image in a link label' => [
                '<p><a href="u"><img src="i" alt="x"></a></p>',
                "[![x](i)](u)\n",
                '<p><a href="u"><img src="i" alt="x"></a></p>',
            ],
            'a bracket in a link label' => [
                '<p><a href="u">a]b</a></p>',
                "[a\\]b](u)\n",
                '<p><a href="u">a]b</a></p>',
            ],
            'a destination standing in for an empty label' => [
                '<p><a href="[x]"></a></p>',
                "[\\[x\\]]([x])\n",
                '<p><a href="[x]">[x]</a></p>',
            ],
            'a backslash in alt text' => [
                '<p><img src="i" alt="a\\b"></p>',
                "![a\\b](i)\n",
                '<img src="i" alt="a\\b">',
            ],
            'balanced brackets in alt text' => [
                '<p><img src="i" alt="a[b]c"></p>',
                "![a[b]c](i)\n",
                '<img src="i" alt="a[b]c">',
            ],
            'a closing bracket in a span' => [
                '<p><span class="k">a]b</span></p>',
                "[a\\]b]{.k}\n",
                '<p><span class="k">a]b</span></p>',
            ],
            'an opening bracket in a span' => [
                '<p><span class="k">[a</span></p>',
                "[\\[a]{.k}\n",
                '<p><span class="k">[a</span></p>',
            ],
            'a closing bracket in a semantic span' => [
                '<p><abbr title="t">a]b</abbr></p>',
                "[a\\]b]{abbr=t}\n",
                '<p><abbr title="t">a]b</abbr></p>',
            ],
            'a bracket in a quote carrying a cite' => [
                '<p><q cite="c">a]b</q></p>',
                "[\"a\\]b\"]{cite=\"c\"}\n",
                "<p><span cite=\"c\">\u{201C}a]b\u{201D}</span></p>",
            ],
            'a bracket in a round-trip inline footnote' => [
                '<p><a href="#x" data-djot-inline-footnote-html="a]b">1</a></p>',
                "[a\\]b]{.fn}\n",
                '<p><span class="fn">a]b</span></p>',
            ],
            'a caret in a destination-less anchor' => [
                '<p><a href="" class="k">^1</a></p>',
                "[\\^1]{.k}\n",
                '<p><span class="k">^1</span></p>',
            ],
        ];
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportIsWritten(string $html, string $carve, string $readBack): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportReadsBackAsTheHtml(string $html, string $carve, string $readBack): void
    {
        $this->assertSame($readBack, trim(CarveConverter::create()->convert((new HtmlToCarve())->convert($html))));
    }

    /**
     * Control: an alt text whose run cannot close keeps the escaped form the
     * writer falls back to.
     */
    public function testAnUnclosableAltTextKeepsTheEscapedForm(): void
    {
        $this->assertSame("![a\\]b](i)\n", (new HtmlToCarve())->convert('<p><img src="i" alt="a]b"></p>'));
    }
}
