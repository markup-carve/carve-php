<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Image;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE BOUNDS. A row that fires on a shape the writer CAN spell is worse than no
 * row: it declares a loss that did not happen, and `docs/html-import.md` reads
 * `structure-unspellable` as the licence to stop comparing the two exits. So
 * the composition is checked, not the direction - every shape below keeps its
 * meaning through the writer and must stay silent.
 */
class AnAuthoredParagraphAroundALoneImageBoundsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function rows(string $html): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string)$row['code'],
            array_filter(
                (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'],
                static fn (array $row): bool => $row['code'] === 'structure-unspellable',
            ),
        ));
    }

    private function carve(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    /**
     * NO AUTHOR PARAGRAPH TO LOSE. A lone image builds a block image at every
     * level with no `<p>` in the input at all, and an image sharing its run
     * builds a real paragraph that `![G](g.jpg) text` re-reads as.
     *
     * @return array<string, array{string, string}>
     */
    public static function silentProvider(): array
    {
        return [
            'a lone image with no paragraph around it' => [
                '<img src="g.jpg" alt="G">',
                "![G](g.jpg)\n",
            ],
            'a lone image inside a div' => [
                '<div><img src="g.jpg" alt="G"></div>',
                "![G](g.jpg)\n",
            ],
            'a paragraph the image shares with text' => [
                '<p><img src="g.jpg" alt="G"> text</p>',
                "![G](g.jpg) text\n",
            ],
            'a paragraph holding two images' => [
                '<p><img src="g.jpg" alt="G"><img src="h.jpg" alt="H"></p>',
                "![G](g.jpg)![H](h.jpg)\n",
            ],
        ];
    }

    #[DataProvider('silentProvider')]
    public function testItReportsNothingWhereNoParagraphIsLost(string $html, string $carve): void
    {
        $this->assertSame($carve, $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * THE SLOTS THAT HOLD INLINE CONTENT, and the over-reach a predicate on the
     * paragraph alone makes. A pipe cell is one line of inline content, a
     * caption line and a definition TERM are inline runs, and a details opener
     * is a quoted title - so none of them ever had a paragraph to lose, whatever
     * the HTML put inside them. A row here would declare a loss that is not
     * there.
     *
     * @return array<string, array{string, string}>
     */
    public static function inlineSlotProvider(): array
    {
        return [
            'a table cell, which holds one line of inline content' => [
                '<table><tr><td><p><img src="g.jpg" alt="G"></p></td></tr></table>',
                "| ![G](g.jpg) |\n",
            ],
            'a table cell reached through a div' => [
                '<table><tr><td><div><p><img src="g.jpg" alt="G"></p></div></td></tr></table>',
                "| ![G](g.jpg) |\n",
            ],
            'a table caption, which is a caption line' => [
                '<table><caption><p><img src="g.jpg" alt="G"></p></caption><tr><td>x</td></tr></table>',
                "| x |\n^ ![G](g.jpg)\n",
            ],
            'a definition term, which is an inline run' => [
                '<dl><dt><p><img src="g.jpg" alt="G"></p></dt><dd>d</dd></dl>',
                ":: ![G](g.jpg)\n:  d\n",
            ],
            'a details opener, which is a quoted title' => [
                '<details><summary><p><img src="g.jpg" alt="G"></p></summary><p>b</p></details>',
                "::: details \"![G](g.jpg)\"\nb\n:::\n",
            ],
        ];
    }

    #[DataProvider('inlineSlotProvider')]
    public function testItReportsNothingWhereTheSlotHoldsInlineContent(string $html, string $carve): void
    {
        $this->assertSame($carve, $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * A CAPTIONED IMAGE TAKES NO ROW, because the figure's target IS the image
     * and there is no paragraph anywhere in the shape.
     */
    public function testACaptionedImageWithNoParagraphTakesNoRow(): void
    {
        $html = '<figure><img src="i.png" alt="a"><figcaption>cap</figcaption></figure>';
        $this->assertSame("![a](i.png)\n^ cap\n", $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * A WRAPPER AN UNWRAPPER ALREADY REMOVED IS NOT A LOSS, and this engine now
     * agrees with carve-js on the shape rather than diverging from it.
     *
     * This expectation used to be the other way. While carve-php#1672 was open,
     * `processFigure()` looked for a DIRECT `<img>` child, so a `<p>`-wrapped
     * one fell through to the generic content path: the figure was lost, the
     * caption came back as an ordinary paragraph, and the image really was
     * written as a bare block - so the row was a true statement about what this
     * engine wrote, and suppressing it then would have left BOTH losses
     * undeclared instead of one.
     *
     * The figure keeps its image now, so the `<p>` is taken off the body before
     * anything is written and there is no bare block to declare. A row that
     * outlived the loss it describes is a stale declaration, and
     * `docs/html-import.md` reads one as licence to stop comparing the exits -
     * which makes it worse than no row at all.
     */
    public function testAParagraphInsideAFigureBodyIsUnwrappedRatherThanLost(): void
    {
        $html = '<figure><p><img src="i.png" alt="a"></p><figcaption>cap</figcaption></figure>';
        $this->assertSame("![a](i.png)\n^ cap\n", $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * THE NEAR MISS, and it is now genuinely near - this provider used to assert
     * the opposite and the premise was wrong.
     *
     * It read: "carve-js parses ` ![G](g.jpg)` as a paragraph holding one image.
     * carve-php reads it as a block image, so there is no indent at which the
     * source says paragraph here at all." markup-carve/carve#1660 ruled carve-js
     * right and moved this engine and carve-rs: a block image is a top-level
     * block construct, so PART 9 section 15's strict column-0 rule reaches it.
     *
     * So an indented spelling DOES exist, and the ceiling stands anyway - which
     * is the stronger form of the same point. The writer declines to reach for
     * it, because indenting would emit meaning-bearing leading whitespace to
     * preserve a wrapper, so the loss is DECLARED rather than routed around.
     * {@see testTheWriterNormalizesRatherThanIndentingOrRefusing()} pins that
     * half; this one pins that it had a choice.
     *
     * @return array<string, array{string, string}>
     */
    public static function indentProvider(): array
    {
        return [
            'at column 0' => ['![G](g.jpg)', 'image'],
            'indented one space' => [' ![G](g.jpg)', 'paragraph'],
            'indented three spaces' => ['   ![G](g.jpg)', 'paragraph'],
        ];
    }

    #[DataProvider('indentProvider')]
    public function testAnIndentSpellsAParagraphHoldingOneImageAndColumnZeroDoesNot(
        string $carve,
        string $expected,
    ): void {
        $children = (new CarveConverter())->parse($carve)->getChildren();
        $this->assertCount(1, $children);
        $this->assertSame($expected, $children[0]->getType());
        if ($expected === 'image') {
            $this->assertInstanceOf(Image::class, $children[0]);

            return;
        }
        // The image has to be INLINE inside the surviving paragraph. A paragraph
        // holding something else would satisfy the type assertion and mean
        // nothing.
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $inlines = $children[0]->getChildren();
        $this->assertCount(1, $inlines);
        $this->assertInstanceOf(Image::class, $inlines[0]);
    }

    /**
     * AND THE WRITER STILL DOES NOT USE IT. The spelling exists in the SOURCE
     * and not in what the writer produces, which is what makes the ceiling a
     * choice rather than a limit of the language.
     */
    public function testTheWriterDoesNotReachForTheIndentedSpelling(): void
    {
        $written = $this->carve('<p><img src="g.jpg" alt="G"></p>');
        $this->assertSame("![G](g.jpg)\n", $written);
        $this->assertStringStartsNotWith(' ', $written);
        $this->assertSame(
            'image',
            (new CarveConverter())->parse($written)->getChildren()[0]->getType(),
        );
    }
}
