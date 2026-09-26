<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §5: a lone bracket inside bracketed content, and a `(` that would
 * open a destination after a paired `]`, are escaped in the minimal form
 * (markup-carve/carve#2357).
 */
class AStructuralBracketEscapeIsUnconditionalTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'a paren that would open a destination' => [
                '<p>[a](b) and f(x) and (see above) and [a] (b) and [a](b c)</p><p><span class="c">[a](u)</span></p>',
                "[a]\\(b) and f(x) and (see above) and [a] (b) and [a](b c)\n\n[[a]\\(u)]{.c}\n",
            ],
            'nested parens in the destination' => ['<p>see [a]((b)) and [a]()</p>', "see [a]\\((b)) and [a]()\n"],
            'text inside emphasis takes part' => ['<p><a href="/y">a <em>[</em> b</a></p>', "[a /\\[/ b](/y)\n"],
            'verbatim content takes no part' => ['<p><a href="/y">a <code>[</code> b</a></p>', "[a `[` b](/y)\n"],
            'a closing paren stays a candidate' => ['<p><span class="c">[a](u) *x*</span></p>', "[[a]\\(u) \\*x*]{.c}\n"],
            'a lone closer in a span' => ['<p><span class="c">x ]</span></p>', "[x \\]]{.c}\n"],
            'a destination spanning an emphasis' => ['<p>[a](<em>b</em>)</p>', "[a]\\(/b/)\n"],
            'a destination holding a spaced code span' => ['<p>[a](<code>x y</code>)</p>', "[a](`x y`)\n"],
            'a delimiter between the bracket and the paren' => ['<p><sup>[1]</sup>(a)</p>', "{^[1]^}(a)\n"],
        ];
    }

    #[DataProvider('provider')]
    public function testTheMinimalFormEscapesOnlyTheStructuralBracket(string $html, string $expected): void
    {
        $this->assertSame($expected, (new HtmlToCarve())->convert($html));
    }
}
