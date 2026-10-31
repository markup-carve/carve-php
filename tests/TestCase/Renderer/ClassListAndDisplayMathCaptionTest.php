<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4203, #4205 and #4115, as the spec model renders them.
 */
class ClassListAndDisplayMathCaptionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'admonition derived classes merge' => [
                "{.note .admonition}\n::: note\n42\n:::\n",
                "<aside class=\"admonition note\" aria-label=\"Note\">\n  <p>42</p>\n</aside>",
            ],
            'typed div kind word merges' => [
                "{.calculation}\n::: calculation\n42\n:::\n",
                "<div class=\"calculation\">\n  <p>42</p>\n</div>",
            ],
            'admonition keeps another kind word' => [
                "{.warning .note}\n::: note\nx\n:::\n",
                "<aside class=\"admonition note warning\" aria-label=\"Note\">\n  <p>x</p>\n</aside>",
            ],
            'class value splits into tokens' => [
                "{class=\"a b\" .a}\npara\n",
                '<p class="a b">para</p>',
            ],
            'each token is hardened on its own' => [
                "{class=\"javascript:x y\"}\npara\n",
                '<p class="y">para</p>',
            ],
            'an empty class value stays one entry' => [
                "{class=\"\"}\npara\n",
                '<p class="">para</p>',
            ],
            'a token dedups against the kind word' => [
                "{class=\"foo x\" .foo}\n::: foo\nx\n:::\n",
                "<div class=\"foo x\">\n  <p>x</p>\n</div>",
            ],
            'multi-line display math takes a caption' => [
                "$$`x\ny`\n^ p\n",
                "<figure>\n  <p><span class=\"math display\" role=\"math\">\\[x\ny\\]</span></p>\n  <figcaption>p</figcaption>\n</figure>",
            ],
            'unclosed multi-line display math takes a caption' => [
                "$$`\n\\\n^ >\n",
                "<figure>\n  <p><span class=\"math display\" role=\"math\">\\[\n\\\\]</span></p>\n  <figcaption>&gt;</figcaption>\n</figure>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testMatchesTheSpecModel(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
