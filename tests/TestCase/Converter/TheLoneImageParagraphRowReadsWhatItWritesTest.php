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
 * carve-php#1673. `<p><picture><img></picture></p>` is written as a bare block
 * image exactly like `<p><img></p>`, and loses the paragraph the same way - but
 * no `structure-unspellable` row came back, so that loss was undeclared.
 *
 * `loneImportImage()` read the `<p>`'s DIRECT children, so an `<img>` behind a
 * wrapper that writes nothing of its own was not recognized even though the
 * writer normalizes it identically. carve-rs has no such gap, because its
 * predicate reads the built inline RUN rather than the DOM shape.
 *
 * THE DISCRIMINATING SET IS BOTH DIRECTIONS AT ONCE, and a fixture holding only
 * the first half cannot see an over-broad fix. A wrapper is transparent only
 * because it contributes no characters; one that DOES contribute makes a
 * paragraph the source can spell, and a row there would declare a loss that did
 * not happen - which `docs/html-import.md` treats as WORSE than a missing row,
 * since a `structure-unspellable` row is read as licence to stop comparing the
 * exits. So a fix that descended blindly through any single-element wrapper
 * would be a regression, and the second provider below is what fails it.
 */
class TheLoneImageParagraphRowReadsWhatItWritesTest extends TestCase
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
     * @return list<string>
     */
    private function rereadKinds(string $carve): array
    {
        return array_map(
            static fn (object $node): string => $node::class,
            (new CarveConverter())->parse($carve)->getChildren(),
        );
    }

    /**
     * THE WRAPPERS THAT WRITE NOTHING OF THEIR OWN. Every one writes
     * `![G](g.jpg)`, which re-reads as a BLOCK image - so the author's `<p>` is
     * gone from the written source in every one of them, and the loss is the
     * same loss the direct spelling already declared.
     *
     * @return array<string, array{string}>
     */
    public static function transparentWrapperProvider(): array
    {
        return [
            // The direct spelling, which already reported before this fix and is
            // the control the others have to agree with.
            'no wrapper at all' => ['<p><img src="g.jpg" alt="G"></p>'],
            'a picture element' => ['<p><picture><img src="g.jpg" alt="G"></picture></p>'],
            'a bare span' => ['<p><span><img src="g.jpg" alt="G"></span></p>'],
            'a figure' => ['<p><figure><img src="g.jpg" alt="G"></figure></p>'],
            'a picture with a source, which writes nothing either' => [
                '<p><picture><source srcset="a.webp"><img src="g.jpg" alt="G"></picture></p>',
            ],
            'two transparent wrappers deep' => [
                '<p><span><picture><img src="g.jpg" alt="G"></picture></span></p>',
            ],
        ];
    }

    #[DataProvider('transparentWrapperProvider')]
    public function testATransparentWrapperDeclaresTheSameLoss(string $html): void
    {
        $written = $this->carve($html);

        // THE PREMISE FIRST: this is the shape only because the paragraph really
        // is gone from what was written. Asserting the row without asserting
        // that would pin a row on a document that kept its paragraph.
        $this->assertSame("![G](g.jpg)\n", $written);
        $this->assertSame([Image::class], $this->rereadKinds($written));

        $this->assertSame(['structure-unspellable'], $this->rows($html));
    }

    /**
     * THE CONTROLS, and they are the half that makes the fix a fix rather than a
     * widening. Each wrapper here writes characters of its own, so the paragraph
     * survives into the source and back out of it - nothing is lost and nothing
     * is owed. A fix that descended through any single-element wrapper reports
     * on every one of these.
     *
     * @return array<string, array{string, string}>
     */
    public static function contributingWrapperProvider(): array
    {
        return [
            'a span carrying a class, which writes an attribute block' => [
                '<p><span class="x"><img src="g.jpg" alt="G"></span></p>',
                "[![G](g.jpg)]{.x}\n",
            ],
            'a link, which writes its destination' => [
                '<p><a href="u"><img src="g.jpg" alt="G"></a></p>',
                "[!\\[G\\](g.jpg)](u)\n",
            ],
            'an emphasis, which writes its own delimiters' => [
                '<p><em><img src="g.jpg" alt="G"></em></p>',
                "/![G](g.jpg)/\n",
            ],
            'a strong, likewise' => [
                '<p><strong><img src="g.jpg" alt="G"></strong></p>',
                "*![G](g.jpg)*\n",
            ],
            'a word the image shares its run with' => [
                '<p><picture><img src="g.jpg" alt="G"></picture> text</p>',
                "![G](g.jpg) text\n",
            ],
            'a second image behind the same wrapper' => [
                '<p><picture><img src="g.jpg" alt="G"><img src="h.jpg" alt="H"></picture></p>',
                "![G](g.jpg)![H](h.jpg)\n",
            ],
        ];
    }

    #[DataProvider('contributingWrapperProvider')]
    public function testAContributingWrapperDeclaresNothing(string $html, string $written): void
    {
        $this->assertSame($written, $this->carve($html));

        // AND THE PARAGRAPH REALLY IS STILL THERE, which is why no row is owed.
        $this->assertSame([Paragraph::class], $this->rereadKinds($written));

        $this->assertSame([], $this->rows($html));
    }

    /**
     * AN IMAGE THAT WRITES NO IMAGE, and this half was WRONG rather than merely
     * missing. `processImage()` has four returns and only two are an image: a
     * `src` naming no destination unwraps to the alt text instead. So
     * `<p><img src=""></p>` writes `G` and re-reads as the PARAGRAPH it was -
     * nothing is lost - and the old predicate reported it anyway, declaring a
     * loss that did not happen.
     *
     * @return array<string, array{string}>
     */
    public static function noImageWrittenProvider(): array
    {
        return [
            'an empty src' => ['<p><img src="" alt="G"></p>'],
            'no src at all' => ['<p><img alt="G"></p>'],
            'an empty src behind a wrapper' => ['<p><picture><img src="" alt="G"></picture></p>'],
        ];
    }

    #[DataProvider('noImageWrittenProvider')]
    public function testAnImageThatWritesNoImageLosesNoParagraph(string $html): void
    {
        $written = $this->carve($html);

        $this->assertSame("G\n", $written);
        $this->assertSame([Paragraph::class], $this->rereadKinds($written));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * THE SLOT STILL DECIDES, and the wider predicate must not reach past it. A
     * pipe cell is one line of inline content, so the `<p>` inside one never had
     * a paragraph to lose whatever wrapper the HTML put around the image -
     * `importParagraphIsWrittenAsABlock()` is what says so, and widening the
     * image search must not route around it.
     *
     * @return array<string, array{string, string}>
     */
    public static function inlineSlotProvider(): array
    {
        return [
            'a table cell' => [
                '<table><tr><td><p><picture><img src="g.jpg" alt="G"></picture></p></td></tr></table>',
                "| ![G](g.jpg) |\n",
            ],
            'a definition term' => [
                '<dl><dt><p><picture><img src="g.jpg" alt="G"></picture></p></dt><dd>d</dd></dl>',
                ":: ![G](g.jpg)\n:  d\n",
            ],
        ];
    }

    #[DataProvider('inlineSlotProvider')]
    public function testAnInlineSlotStillDeclaresNothing(string $html, string $written): void
    {
        $this->assertSame($written, $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * THE MESSAGE STILL NAMES WHAT THE PARAGRAPH CARRIED. The row has three
     * outcomes and says which one happened; a wrapper does not change that,
     * because the attributes still move from the `<p>` onto the IMAGE and the
     * image's own value still wins where both set the same name.
     */
    public function testTheMessageBehindAWrapperStillNamesTheOverwrittenAttribute(): void
    {
        $html = '<p id="p"><picture><img id="i" src="g.jpg" alt="G"></picture></p>';

        $messages = array_values(array_map(
            static fn (array $row): string => (string)$row['message'],
            array_filter(
                (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'],
                static fn (array $row): bool => $row['code'] === 'structure-unspellable',
            ),
        ));

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('except id', $messages[0]);
    }
}
