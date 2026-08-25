<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#1755: `{align=left|right|center}` on an element whose
 * `align` means TEXT ALIGNMENT renders the CSS declaration instead of the
 * deprecated presentational attribute.
 */
class AnAlignedTextBlockRendersAStyleDeclarationTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function textBlockProvider(): array
    {
        return [
            'a paragraph' => ["{align=right}\npara\n", '<p style="text-align: right;">para</p>'],
            'a heading' => ["{align=left}\n# H\n", '<h1 style="text-align: left;">H</h1>'],
            'a div' => ["{align=center}\n::: box\nx\n:::\n", '<div class="box" style="text-align: center;">'],
        ];
    }

    #[DataProvider('textBlockProvider')]
    public function testATextBlockRendersTheDeclaration(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->converter->convert($source));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valueProvider(): array
    {
        return ['left' => ['left'], 'right' => ['right'], 'center' => ['center']];
    }

    #[DataProvider('valueProvider')]
    public function testEveryRuledValue(string $value): void
    {
        $this->assertStringContainsString(
            '<p style="text-align: ' . $value . ';">para</p>',
            $this->converter->convert('{align=' . $value . "}\npara\n"),
        );
    }

    public function testTheDeprecatedAttributeIsGoneWhereTheDeclarationBelongs(): void
    {
        $this->assertStringNotContainsString('align="right"', $this->converter->convert("{align=right}\npara\n"));
    }

    public function testAnAuthorStyleKeepsOneAttributeWithTheDeclarationAppended(): void
    {
        $this->assertStringContainsString(
            '<p style="color: red; text-align: right;">para</p>',
            $this->converter->convert("{align=right style=\"color: red\"}\npara\n"),
        );
    }

    /**
     * ON A TABLE `align` IS PLACEMENT, NOT TEXT ALIGNMENT - the table floats
     * left or right, or centres as a block. Rewriting it to `text-align` would
     * silently right-align the CELL TEXT of every floated table instead of
     * floating it, so the table is scoped out of the ruling and keeps the
     * legacy attribute. Do not "tidy" this into the set above.
     */
    public function testATableKeepsThePlacementAttribute(): void
    {
        $this->assertStringContainsString('<table align="right">', $this->converter->convert("{align=right}\n| a |\n"));
    }

    /**
     * The same reason: HTML maps `align` on an image to a float, never to
     * `text-align`.
     */
    public function testAnImageKeepsThePlacementAttribute(): void
    {
        $this->assertStringContainsString('align="right"', $this->converter->convert("{align=right}\n![alt](x.png)\n"));
    }

    public function testTheRawPassThroughIsUntouchedForEveryOtherKey(): void
    {
        $this->assertStringContainsString(
            '<p banana="yellow">para</p>',
            $this->converter->convert("{banana=yellow}\npara\n"),
        );
    }

    /**
     * markup-carve/carve#1756 ruled `{valign=...}` working as designed.
     */
    public function testValignOffACellIsUnchanged(): void
    {
        $this->assertStringContainsString('<p valign="top">para</p>', $this->converter->convert("{valign=top}\npara\n"));
    }

    /**
     * Only the three values HTML gives a `text-align` meaning are rewritten.
     */
    public function testAValueOutsideTheRuledSetPassesThroughRaw(): void
    {
        $this->assertStringContainsString(
            '<p align="justify">para</p>',
            $this->converter->convert("{align=justify}\npara\n"),
        );
    }

    public function testACellAlignmentMarkerStillRendersItsOwnDeclaration(): void
    {
        $this->assertStringContainsString(
            '<td style="text-align: right;">a</td>',
            $this->converter->convert("|> a | b |\n"),
        );
    }

    public function testHtmlToCarveToHtmlIsAFixedPointForAnAlignedParagraph(): void
    {
        $source = '<p style="text-align: right;">x</p>';
        $once = trim($this->converter->convert((new HtmlToCarve(importMode: 'roundtrip'))->convert($source)));
        $this->assertSame($source, $once);

        $twice = trim($this->converter->convert((new HtmlToCarve(importMode: 'roundtrip'))->convert($once)));
        $this->assertSame($once, $twice);
    }
}
