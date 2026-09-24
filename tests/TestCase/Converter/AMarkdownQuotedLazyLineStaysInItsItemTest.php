<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * In a block quote, a lazy line of an item's paragraph is written at the
 * item's content column and a sibling item at its list's column, as they are
 * outside a quote. Left where they stood, Carve folded the next item into the
 * paragraph, since no list marker interrupts one.
 */
class AMarkdownQuotedLazyLineStaysInItsItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a lazy line before a sibling' => ["> - a\n> x\n> - b\n", "> - a\n>   x\n> - b\n", "<blockquote>\n  <ul>\n    <li>a\nx</li>\n    <li>b</li>\n  </ul>\n</blockquote>\n"],
            'a sibling one column in' => ["> - a\n> x\n>  - b\n", "> - a\n>   x\n> - b\n", "<blockquote>\n  <ul>\n    <li>a\nx</li>\n    <li>b</li>\n  </ul>\n</blockquote>\n"],
            'two lazy lines' => ["> - a\n> x\n> y\n> - b\n", "> - a\n>   x\n>   y\n> - b\n", "<blockquote>\n  <ul>\n    <li>a\nx\ny</li>\n    <li>b</li>\n  </ul>\n</blockquote>\n"],
            'an ordered list' => ["> 1. a\n> x\n> 2. b\n", "> 1. a\n>    x\n> 2. b\n", "<blockquote>\n  <ol>\n    <li>a\nx</li>\n    <li>b</li>\n  </ol>\n</blockquote>\n"],
            'a nested item' => ["> - a\n>   - b\n> x\n>   - c\n", "> - a\n>   - b\n>     x\n>   - c\n", "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li>b\nx</li>\n        <li>c</li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>\n"],
            'a nested quote' => ["> > - a\n> > x\n> > - b\n", "> > - a\n> >   x\n> > - b\n", "<blockquote>\n  <blockquote>\n    <ul>\n      <li>a\nx</li>\n      <li>b</li>\n    </ul>\n  </blockquote>\n</blockquote>\n"],
            'a sibling one column in with no lazy line' => ["> - a\n>  - b\n", "> - a\n> - b\n", "<blockquote>\n  <ul>\n    <li>a</li>\n    <li>b</li>\n  </ul>\n</blockquote>\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheLineStaysInItsItem(string $markdown, string $carve, string $html): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($html, (new CarveConverter())->convert($imported));
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }
}
