<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1672. A `<figure>` whose image sits inside a `<p>` lost the figure
 * ENTIRELY: `processFigure()` looked for a DIRECT `<img>` child, found none, and
 * the whole element fell through to the generic content path, which writes a
 * `<figcaption>` as an ordinary block.
 *
 * THIS IS NOT A LOSS INSIDE A DECLARED CEILING. carve#1636 permits a loss and
 * forbids an ADDITION, and an unbound caption is the second: `![a](i.png)`, a
 * blank line, then `cap` re-reads as a block image and an unrelated paragraph,
 * so the document asserts a paragraph the author never wrote and the caption
 * belongs to nothing. A `<p>` around a figure's image is what every WYSIWYG
 * editor produces, so this is the common spelling rather than an exotic one.
 *
 * THE PREDICATE READS WHAT THE BODY WRITES, NOT WHAT SHAPE IT IS - the same
 * lesson as carve-php#1673 one level down. So the bounds are what make the fix
 * honest, and they are pinned below alongside it: a wrapper that contributes
 * characters of its own is NOT this shape and keeps the generic path.
 */
class AFigureFindsItsImageBehindAWrapperTest extends TestCase
{
    private function carve(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    /**
     * @return list<string>
     */
    private function codes(string $html): array
    {
        return array_map(
            static fn (array $row): string => (string)$row['code'],
            (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'],
        );
    }

    /**
     * The wrappers that write nothing of their own, and the direct spelling they
     * have to agree with. `<p>` is the ticket's shape; `<picture>` and `<div>`
     * are the same defect reached differently, and `<picture>` was WORSE than an
     * unbound caption - the generic path wrote `![a](i.png)cap` on one line,
     * concatenating the caption into the image's own paragraph.
     *
     * @return array<string, array{string}>
     */
    public static function transparentWrapperProvider(): array
    {
        return [
            'no wrapper at all, the shape that already worked' => [
                '<figure><img src="i.png" alt="a"><figcaption>cap</figcaption></figure>',
            ],
            'a paragraph, which is what a WYSIWYG editor writes' => [
                '<figure><p><img src="i.png" alt="a"></p><figcaption>cap</figcaption></figure>',
            ],
            'a picture element' => [
                '<figure><picture><img src="i.png" alt="a"></picture><figcaption>cap</figcaption></figure>',
            ],
            'a picture with a source, which writes nothing either' => [
                '<figure><picture><source srcset="a.webp"><img src="i.png" alt="a"></picture>'
                    . '<figcaption>cap</figcaption></figure>',
            ],
            'a div' => [
                '<figure><div><img src="i.png" alt="a"></div><figcaption>cap</figcaption></figure>',
            ],
            'a paragraph inside a picture, two transparent wrappers deep' => [
                '<figure><p><picture><img src="i.png" alt="a"></picture></p><figcaption>cap</figcaption></figure>',
            ],
        ];
    }

    /**
     * THE CAPTION IS BOUND, WHICH IS THE CLAIM - not that a `^` line appears
     * somewhere. So the written source is READ BACK and the figure has to be
     * there, which is the only thing that distinguishes a caption from a
     * paragraph that happens to sit under an image.
     */
    #[DataProvider('transparentWrapperProvider')]
    public function testTheCaptionComesBackBoundToTheImage(string $html): void
    {
        $written = $this->carve($html);

        $this->assertSame("![a](i.png)\n^ cap\n", $written);
        $this->assertSame(
            '<figure>' . "\n" . '  <img src="i.png" alt="a">' . "\n"
                . '  <figcaption>cap</figcaption>' . "\n" . '</figure>' . "\n",
            (new CarveConverter())->convert($written),
            'the written source has to re-read as the figure the HTML held',
        );
    }

    /**
     * NO ROW IS OWED ONCE THE FIGURE KEEPS ITS IMAGE, and this is the half that
     * reconciles with carve-php#1667. That fix reports `structure-unspellable`
     * for an author's `<p>` around a lone image, and it deliberately fired here
     * while the figure was being lost, because the image really was written as a
     * bare block. Now the figure takes the paragraph off before anything is
     * written, so there is no such block and no such row - a row that outlived
     * the loss it describes is a stale declaration, which
     * `docs/html-import.md` reads as licence to stop comparing the exits.
     */
    #[DataProvider('transparentWrapperProvider')]
    public function testAnUnwrappedFigureBodyDeclaresNoParagraphLoss(string $html): void
    {
        $this->assertNotContains('structure-unspellable', $this->codes($html));
    }

    /**
     * THE BOUNDS, and they are what a blind unwrapper would break. Each wrapper
     * here contributes characters of its own, so the body does NOT write the
     * image alone and the figure is not this shape. Every one of these keeps the
     * behavior it had before the fix.
     *
     * @return array<string, array{string, string}>
     */
    public static function contributingBodyProvider(): array
    {
        return [
            // The link is what the body writes; unwrapping it would drop the
            // destination the HTML held.
            'a link around the image' => [
                '<figure><p><a href="u"><img src="i.png" alt="a"></a></p><figcaption>cap</figcaption></figure>',
                "[!\\[a\\](i.png)](u)\n\ncap\n",
            ],
            // The paragraph writes an attribute line ABOVE the image, so it is
            // not writing the image alone.
            'an attributed paragraph' => [
                '<figure><p class="x"><img src="i.png" alt="a"></p><figcaption>cap</figcaption></figure>',
                "{.x}\n![a](i.png)\n\ncap\n",
            ],
            'a paragraph the image shares with text' => [
                '<figure><p><img src="i.png" alt="a"> t</p><figcaption>cap</figcaption></figure>',
                "![a](i.png) t\n\ncap\n",
            ],
            'a paragraph holding two images' => [
                '<figure><p><img src="i.png" alt="a"><img src="j.png" alt="b"></p>'
                    . '<figcaption>cap</figcaption></figure>',
                "![a](i.png)![b](j.png)\n\ncap\n",
            ],
            'a second body block beside the image' => [
                '<figure><p><img src="i.png" alt="a"></p><p>t</p><figcaption>cap</figcaption></figure>',
                "![a](i.png)\n\nt\n\ncap\n",
            ],
            // A CAPTION NEEDS A BLOCK TO BIND TO. This body writes the alt
            // text and no image, and `^ cap` under a bare word is swallowed as
            // literal text rather than binding - so unwrapping here would
            // INVENT the characters `^ cap`, which carve#1636 forbids outright.
            // The generic path takes the loss instead.
            'an image whose src names no destination, so it writes no image' => [
                '<figure><p><img src="" alt="a"></p><figcaption>cap</figcaption></figure>',
                "a\n\ncap\n",
            ],
        ];
    }

    #[DataProvider('contributingBodyProvider')]
    public function testAContributingBodyKeepsTheGenericPath(string $html, string $written): void
    {
        $this->assertSame($written, $this->carve($html));
    }

    /**
     * A TRIAL WRITE ADDS NOTHING TO THE DOCUMENT EITHER, and this is the half a
     * loss-registry-only restore missed. Asking an `<img>` what it writes
     * COLLECTS the reference definition its output would need, and those are
     * emitted at the end of the document whether or not the image was ever
     * written. Here the body suppresses the image entirely, so the conversion
     * emitted a dangling `[r]: g.jpg` that the input never held - an ADDITION,
     * which markup-carve/carve#1636 forbids outright, and one this fix
     * INTRODUCED rather than inherited.
     */
    public function testATrialWriteLeavesNoDanglingDefinitionBehind(): void
    {
        $html = '<figure><noscript><img src="g.jpg" alt="G" data-djot-ref="r"></noscript>'
            . '<figcaption>cap</figcaption></figure>';

        $this->assertSame("cap\n", $this->carve($html));
    }

    /**
     * A TRIAL WRITE RECORDS NOTHING. Asking what a body WRITES is a question,
     * not an exit, and the writer records the losses it takes as it takes them.
     * A trial write that left those records behind would double the list-shaped
     * ones when the real write follows, so a body that both takes a loss and is
     * then written for real must report that loss exactly once.
     */
    public function testATrialWriteDoesNotDoubleTheLossesTheRealWriteRecords(): void
    {
        // The body holds an image AND a `<dd>` that writes nothing, so the
        // transparency question is asked, answered "no", and the generic path
        // then writes the same subtree for real.
        $html = '<figure><div><img src="i.png" alt="a"><dl><dt>t</dt><dd></dd></dl></div>'
            . '<figcaption>cap</figcaption></figure>';

        $codes = $this->codes($html);

        $this->assertSame(
            1,
            count(array_filter($codes, static fn (string $code): bool => $code === 'structure-unspellable')),
            'the dropped description is one loss, reported once: ' . implode(',', $codes),
        );
    }
}
