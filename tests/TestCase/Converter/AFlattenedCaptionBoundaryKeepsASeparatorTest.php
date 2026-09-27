<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AFlattenedCaptionBoundaryKeepsASeparatorTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function captionProvider(): array
    {
        return [
            'block and text neighbors' => ['<p>one</p>two <div>three</div>', 'one two three'],
            'linked magnifier' => ['<div class="magnify"><a href="/big">x</a></div>Districts', '[x](/big) Districts'],
            'empty block after text' => ['one<div></div>two', 'onetwo'],
            'empty block after block' => ['<p>one</p><div></div>two', 'one two'],
            'nonbreaking space at block end' => ['<p>one&nbsp;</p>two', "one\u{00A0} two"],
            'nonbreaking space at block start' => ['one<p>&nbsp;two</p>', "one \u{00A0}two"],
            'hard break at block end' => ['<p>one<br></p>two', "one\\\ntwo"],
            'hard break at block start' => ['one<p><br>two</p>', "one\\\ntwo"],
        ];
    }

    #[DataProvider('captionProvider')]
    public function testFlattenedBoundaries(string $caption, string $expected): void
    {
        $html = '<figure><img src="/i" alt="x"><figcaption>' . $caption . '</figcaption></figure>';
        $this->assertSame("![x](/i)\n^ " . $expected . "\n", (new HtmlToCarve())->convert($html));
    }

    public function testMagnifierCaption(): void
    {
        $html = '<figure><img src="/m.png" alt="Map"><figcaption>'
            . '<div class="magnify"><a href="/big">x</a></div>Districts</figcaption></figure>';
        $this->assertSame("![Map](/m.png)\n^ [x](/big) Districts\n", (new HtmlToCarve())->convert($html));
    }
}
