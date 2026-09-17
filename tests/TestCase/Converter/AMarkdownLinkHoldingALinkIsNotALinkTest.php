<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CommonMark lets no link hold a link, so the outer brackets of a nested pair
 * stay literal (markup-carve/carve-php#2115). Each expected value is
 * markdown-it's commonmark HTML with the whitespace between tags removed.
 */
class AMarkdownLinkHoldingALinkIsNotALinkTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'the ticket shape' => ['[foo [bar](/uri)](/uri)', '<p>[foo <a href="/uri">bar</a>](/uri)</p>'],
            'an empty outer destination' => ['[a [d](u)]()', '<p>[a <a href="u">d</a>]()</p>'],
            'an empty outer destination around a reference' => ["[a [d][r]]()\n\n[r]: /x", '<p>[a <a href="/x">d</a>]()</p>'],
            'an image inside a link is allowed' => ['[![moon](/moon.jpg)](/uri)', '<p><a href="/uri"><img src="/moon.jpg" alt="moon" /></a></p>'],
            'a full reference inside' => ["[foo [bar][r]](/uri)\n\n[r]: /x", '<p>[foo <a href="/x">bar</a>](/uri)</p>'],
            'a collapsed reference inside' => ["[foo [bar][]](/uri)\n\n[bar]: /x", '<p>[foo <a href="/x">bar</a>](/uri)</p>'],
            'a shortcut reference inside' => ["[foo [bar]](/uri)\n\n[bar]: /x", '<p>[foo <a href="/x">bar</a>](/uri)</p>'],
            'a link inside emphasis inside a link' => ['[foo *[bar](/u)*](/uri)', '<p>[foo <em><a href="/u">bar</a></em>](/uri)</p>'],
            'two levels of nesting' => ['[a [b [c](/u)](/v)](/w)', '<p>[a [b <a href="/u">c</a>](/v)](/w)</p>'],
            'an outer reference tail' => ["[foo [bar](/uri)][ref]\n\n[ref]: /y", '<p>[foo <a href="/uri">bar</a>]<a href="/y">ref</a></p>'],
            'an outer title' => ['x [foo [bar](/uri "t")](/uri "s") y', '<p>x [foo <a href="/uri" title="t">bar</a>](/uri &quot;s&quot;) y</p>'],
            'an image and a link inside' => ['[![a](/i) [b](/u)](/v)', '<p>[<img src="/i" alt="a" /><a href="/u">b</a>](/v)</p>'],
            'two nested pairs on one line' => ['[a [b](/u)](/v) [c [d](/w)](/x)', '<p>[a <a href="/u">b</a>](/v) [c <a href="/w">d</a>](/x)</p>'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheImportRendersWhatCommonMarkRenders(string $markdown, string $commonMark): void
    {
        $html = CarveConverter::create()->convert((new MarkdownToCarve())->convert($markdown));

        $this->assertSame($this->normalize($commonMark), $this->normalize($html));
    }

    /**
     * BOUND: a link whose text holds no link keeps its bytes.
     *
     * @return array<string, array{string}>
     */
    public static function untouched(): array
    {
        return [
            'side by side' => ['[a](/u) [b](/v)'],
            'brackets without a link' => ['[a [b] c](/v)'],
            'a link in a code span' => ['[a `[b](/u)`](/v)'],
            'an escaped inner opener' => ['[a \\[b](/u)](/v)'],
        ];
    }

    #[DataProvider('untouched')]
    public function testALinkHoldingNoLinkIsCopied(string $markdown): void
    {
        $this->assertSame($markdown, (new MarkdownToCarve())->convert($markdown));
    }

    private function normalize(string $html): string
    {
        return str_replace([' />', '&quot;'], ['>', '"'], preg_replace('/>\s+</', '><', trim($html)) ?? $html);
    }
}
