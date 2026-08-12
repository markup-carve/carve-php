<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown importer matches GFM by default
 * (markup-carve/carve-php#1222).
 *
 * Every expectation here was taken from `marked` in GFM mode rather than from
 * recollection of the spec.
 */
class MarkdownDialectTest extends TestCase
{
    private function render(string $carve): string
    {
        return trim(CarveConverter::create()->convert($carve));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gfmProvider(): array
    {
        return [
            // GFM strikethrough is a matching pair of ONE or two tildes.
            'a paired single tilde' => ["a ~b~ c\n", '<p>a <s>b</s> c</p>'],
            'a paired double tilde' => ["a ~~b~~ c\n", '<p>a <s>b</s> c</p>'],
            // BOUND: unpaired tildes were already literal and stay literal.
            'a lone tilde' => ["a ~ b\n", '<p>a ~ b</p>'],
            'an unclosed tilde' => ["a ~b c\n", '<p>a ~b c</p>'],
            'tildes in paths' => ["path ~/a and ~/b\n", '<p>path ~/a and ~/b</p>'],
            // `==x==` is literal text in CommonMark and in GFM.
            'a doubled equals' => ["a ==b== c\n", '<p>a ==b== c</p>'],
        ];
    }

    #[DataProvider('gfmProvider')]
    public function testTheDefaultMatchesGfm(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render((new MarkdownToCarve())->convert($markdown)));
    }

    /**
     * Obsidian, Quarto and pandoc's `mark` extension DO mean a highlight by
     * `==x==`, so it is available - opt in, the way `convertMath` is.
     */
    public function testHighlightIsOptIn(): void
    {
        $converter = new MarkdownToCarve(convertHighlight: true);

        $this->assertSame(
            '<p>a <mark>b</mark> c</p>',
            $this->render($converter->convert("a ==b== c\n")),
        );
    }

    /**
     * BOUND: the existing math flag is untouched, and the two are independent.
     */
    public function testTheMathFlagIsUnaffected(): void
    {
        $this->assertSame(
            '<p>a ==b== c</p>',
            $this->render((new MarkdownToCarve(convertMath: true))->convert("a ==b== c\n")),
        );
    }

    /**
     * A brace around a strikethrough keeps the brace: GFM shows it and Carve
     * would otherwise read `{~x~}` as a braced strikethrough and eat it.
     */
    public function testABracedStrikethroughKeepsItsBraces(): void
    {
        $this->assertSame(
            '<p>a {<s>x</s>} b</p>',
            $this->render((new MarkdownToCarve())->convert("a {~x~} b\n")),
        );
    }
}
