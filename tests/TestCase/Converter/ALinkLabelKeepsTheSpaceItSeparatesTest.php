<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A link label's edge space stands outside it, where it still separates the
 * label from its neighbor (#2094, markup-carve/carve#2361).
 */
class ALinkLabelKeepsTheSpaceItSeparatesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a trailing space before text' => ['<p><a href="u">x </a>y</p>', "[x](u) y\n"],
            'a leading space after text' => ['<p>x<a href="u"> y</a></p>', "x [y](u)\n"],
            'a title' => ['<p><a href="u" title="t">x </a>y</p>', "[x](u \"t\") y\n"],
            'a full reference' => ['<p><a href="u" data-djot-ref="r">x </a>y</p>', "[x][r] y\n\n[r]: u\n"],
            'a collapsed reference' => ['<p><a href="u" data-djot-ref="">x </a>y</p>', "[x][] y\n\n[x]: u\n"],
            'inside an emphasis' => ['<p><em><a href="u">x </a></em>y</p>', "{/[x](u) /}y\n"],
            'two links keep one space' => ['<p><a href="u">x </a><a href="v"> y</a></p>', "[x](u) [y](v)\n"],
            'the neighbor has its own space' => ['<p><a href="u">x </a> y</p>', "[x](u) y\n"],
            'the block ends' => ['<p><a href="u">x </a></p>', "[x](u)\n"],
            'an unpadded collapsed reference' => ['<p><a href="u" data-djot-ref="">x</a> y</p>', "[x][] y\n\n[x]: u\n"],
            'a strong beside a link' => ['<p><a href="u">x </a><strong> y</strong></p>', "[x](u){* y*}\n"],
            'a strong beside an autolink' => [
                '<p><a href="https://e.test" data-djot-autolink>https://e.test </a><strong> y</strong></p>',
                "<https://e.test>{* y*}\n",
            ],
        ];
    }

    /**
     * An anchor with no destination writes its content bare, and an anchor the
     * trusted round trip re-emits as raw HTML writes no label either, so in
     * both the neighbor keeps the space.
     */
    public function testALinkThatWritesNoLabelLeavesTheSpaceToItsNeighbor(): void
    {
        $this->assertSame("x {* y*}\n", (new HtmlToCarve())->convert('<p><a href="">x </a><strong> y</strong></p>'));

        $trusted = new HtmlToCarve(true);
        $this->assertSame(
            "`<a href=\"u\"><img src=\"i\" alt=\"a]b[\"> </a>`{=html}{* y*}\n",
            $trusted->convert('<p><a href="u"><img src="i" alt="a]b["> </a><strong> y</strong></p>'),
        );
    }

    /**
     * A note reference writes no label, so the space stays with its neighbor.
     * Its read-back is the rendered reference rather than the text, so it is
     * checked for bytes only.
     */
    public function testANoteReferenceLeavesTheSpaceToItsNeighbor(): void
    {
        $html = '<p><a href="#fn1" data-djot-footnote-label="1">1 </a><strong> y</strong></p>';

        $this->assertSame("[^1]{* y*}\n", (new HtmlToCarve())->convert($html));
    }

    #[DataProvider('shapes')]
    public function testTheSeparatingSpaceIsKept(string $html, string $carve): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    #[DataProvider('shapes')]
    public function testTheImportReadsBackAsTheHtmlSpaces(string $html, string $carve): void
    {
        $text = static fn (string $markup): string => trim((string)preg_replace('/\s+/', ' ', strip_tags(explode('<ol', $markup)[0])));

        $this->assertSame($text($html), $text((new CarveConverter())->convert($carve)));
    }
}
