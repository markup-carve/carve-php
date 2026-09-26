<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whitespace at a formatting element's edge is trimmed, except one space that
 * is all that separates its content from a neighbor (#2079).
 */
class APaddedSpanKeepsTheSpaceItSeparatesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a trailing space before text' => ['<p><strong>x </strong>y</p>', "{*x *}y\n"],
            'a leading space after text' => ['<p>a<em> x</em></p>', "a{/ x/}\n"],
            'a braced-only kind' => ['<p><sup>x </sup>y</p>', "{^x ^}y\n"],
            'a trailing space before punctuation' => ['<p><strong>x </strong>.</p>', "{*x *}.\n"],
            'a trailing space before another element' => ['<p><strong>x </strong><em>y</em></p>', "{*x *}/y/\n"],
            'read past an inline parent' => ['<p><em><strong>x </strong></em>y</p>', "{/{*x *}/}y\n"],
            'a whitespace run keeps one space' => ["<p><strong>x \n </strong>y</p>", "{*x *}y\n"],
            'the neighbor has its own space' => ['<p><strong>x </strong> y</p>', "*x* y\n"],
            'the block ends' => ['<p><strong>x </strong></p>', "*x*\n"],
            'a hard break follows' => ['<p><strong>x </strong><br>y</p>', "*x*\\\ny\n"],
            'a trailing hard break is not padding' => ['<p><strong>x<br></strong>y</p>', "{*x\\\n*}y\n"],
            'an empty element before the block end' => ['<p><strong>x </strong><span></span></p>', "*x*\n"],
            'an empty element before text' => ['<p><strong>x </strong><span></span>y</p>', "{*x *}y\n"],
            'an element whose text starts with a space' => ['<p><strong>x </strong><span> y</span></p>', "*x* y\n"],
            'an image follows' => ['<p><strong>x </strong><img src="a.png" alt="i"></p>', "{*x *}![i](a.png)\n"],
            'a linked image follows' => ['<p><strong>x </strong><a href="u"><img src="a.png" alt="i"></a></p>', "{*x *}[![i](a.png)](u)\n"],
            'a wrapped hard break follows' => ['<p><strong>x </strong><span><br></span>y</p>', "*x*\\\ny\n"],
            'two padded elements keep one space' => ['<p><strong>x </strong><em> y </em>z</p>', "{*x *}{/y /}z\n"],
            'an unpadded element before a padded one' => ['<p><strong>x</strong><em> y</em></p>', "*x*{/ y/}\n"],
            'a link with a leading space follows' => ['<p><strong>x </strong><a href="u"> y</a></p>', "{*x *}[y](u)\n"],
            'a link with a trailing space precedes' => ['<p><a href="u">x </a><strong> y</strong></p>', "[x](u){* y*}\n"],
            'a code span keeps its own space' => ['<p><strong>x </strong><code> y</code></p>', "*x*` y`\n"],
            'a leading space before an inner hard break' => ['<p>x<strong> <br>y</strong></p>', "x{*\\\ny*}\n"],
            'a block follows' => ['<div><strong>x </strong><p>y</p></div>', "*x*\n\ny\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheSeparatingSpaceIsKept(string $html, string $carve): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }
}
