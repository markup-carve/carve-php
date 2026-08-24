<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1676. Two ways a `<figure>` that cannot keep its caption slot
 * INVENTED text, rather than merely losing the binding.
 *
 * markup-carve/carve#1636 permits a loss inside a declared ceiling and forbids
 * an ADDITION outright. A caption that comes back as an ordinary paragraph has
 * lost its binding, which is the permitted half. Characters that were never in
 * the input are the other half, and both shapes below produced them.
 *
 * - THE CAPTION RAN INTO AN INLINE BODY. `processGenericFigureContent()` writes
 *   the caption as ordinary blocks, but a body that writes INLINE content leaves
 *   no block boundary behind it, so the two were concatenated:
 *   `<figure><span>b</span><figcaption>cap</figcaption></figure>` wrote `bcap`,
 *   one word that is in neither.
 * - THE `^` LINE LANDED UNDER SOMETHING THAT IS NOT A BLOCK. A caption binds to
 *   the block above it, so under a bare word the marker is read as a lazy
 *   continuation line and becomes prose: `<figure><img src="">` wrote `a` then
 *   `^ cap` and re-read as ONE paragraph holding the literal characters
 *   `^ cap`.
 *
 * THE TWO ARE ONE TICKET BECAUSE THEY BLOCK EACH OTHER. The second is fixed by
 * sending such a body down the generic path - which was the wrong move while
 * that path still concatenated, since it turned a stray `^ cap` into the worse
 * `acap`. carve-php#1672 left the branch alone for exactly that reason and said
 * so; the separator has to land first.
 */
class AFigureCaptionNeverRunsIntoItsBodyTest extends TestCase
{
    private function carve(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    private function rendered(string $html): string
    {
        return (new CarveConverter())->convert($this->carve($html));
    }

    /**
     * EVERY ONE OF THESE PRODUCED A TOKEN THAT IS IN NEITHER THE BODY NOR THE
     * CAPTION. The provider carries that token, so the assertion is about the
     * invention rather than about the exact spelling that replaced it.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function inventedTextProvider(): array
    {
        return [
            'an inline body ran into the caption' => [
                '<figure><span>b</span><figcaption>cap</figcaption></figure>',
                'bcap',
                "b\n\ncap\n",
            ],
            'a bare text body, likewise' => [
                '<figure>b<figcaption>cap</figcaption></figure>',
                'bcap',
                "b\n\ncap\n",
            ],
            'a link-wrapped image, which is an inline body too' => [
                '<figure><a href="u"><img src="i.png" alt="a"></a><figcaption>cap</figcaption></figure>',
                '(u)cap',
                "[!\\[a\\](i.png)](u)\n\ncap\n",
            ],
            'an inline RUN of two children ran into the caption' => [
                '<figure><span>b</span><em>c</em><figcaption>cap</figcaption></figure>',
                'ccap',
                "b{/c/}\n\ncap\n",
            ],
            'a caption marker under a target that writes no image' => [
                '<figure><img src="" alt="a"><figcaption>cap</figcaption></figure>',
                '^ cap',
                "a\n\ncap\n",
            ],
            'the same, behind a wrapper' => [
                '<figure><p><img src="" alt="a"></p><figcaption>cap</figcaption></figure>',
                '^ cap',
                "a\n\ncap\n",
            ],
        ];
    }

    /**
     * THE CLAIM IS ABOUT THE RENDERED DOCUMENT, not only about the source. The
     * `^ cap` half is invisible in the written text - the characters are there
     * legitimately in a working caption - and only shows up once the source is
     * read back and the marker turns out to be prose. So both are asserted.
     */
    #[DataProvider('inventedTextProvider')]
    public function testNoTokenAppearsThatNeitherHalfHeld(string $html, string $invented, string $written): void
    {
        $this->assertSame($written, $this->carve($html));
        $this->assertStringNotContainsString($invented, $this->rendered($html));

        // AND BOTH HALVES SURVIVE, which is what makes it a loss rather than a
        // different kind of damage. Separating them by dropping one would pass
        // the assertion above.
        $this->assertStringContainsString('cap', $this->rendered($html));
    }

    /**
     * THE BOUND THAT A BLANKET SEPARATOR BREAKS. Consecutive inline children of
     * a figure body are ONE run and must stay one: a helper that put a boundary
     * between every contribution would split `<span>b</span><em>c</em>` into two
     * paragraphs, destroying structure the output can hold. The boundary belongs
     * at the join with the caption and nowhere else.
     */
    public function testAnInlineRunInTheBodyStaysOneRun(): void
    {
        $html = '<figure><span>b</span><em>c</em><figcaption>cap</figcaption></figure>';

        $this->assertSame("b{/c/}\n\ncap\n", $this->carve($html));
        $this->assertStringContainsString('<p>b<em>c</em></p>', $this->rendered($html));
    }

    /**
     * THE SHAPES THAT ALREADY ENDED IN A BLOCK ARE UNTOUCHED, which is what
     * shows the change is a boundary and not a reformat.
     *
     * @return array<string, array{string, string}>
     */
    public static function unchangedProvider(): array
    {
        return [
            'a block body, which already ended in a blank line' => [
                '<figure><p>b</p><figcaption>cap</figcaption></figure>',
                "b\n\ncap\n",
            ],
            'two block bodies' => [
                '<figure><p>a</p><p>b</p><figcaption>cap</figcaption></figure>',
                "a\n\nb\n\ncap\n",
            ],
            'a caption written before the body' => [
                '<figure><figcaption>cap</figcaption><span>b</span></figure>',
                "cap\n\nb\n",
            ],
            // The figure this engine KEEPS is not this path at all, and must not
            // be dragged onto it (carve-php#1672).
            'a figure that keeps its image and its caption slot' => [
                '<figure><p><img src="i.png" alt="a"></p><figcaption>cap</figcaption></figure>',
                "![a](i.png)\n^ cap\n",
            ],
            'the direct spelling of the same' => [
                '<figure><img src="i.png" alt="a"><figcaption>cap</figcaption></figure>',
                "![a](i.png)\n^ cap\n",
            ],
            'a captioned blockquote, whose target is a block already' => [
                '<figure><blockquote><p>q</p></blockquote><figcaption>cap</figcaption></figure>',
                "> q\n^ cap\n",
            ],
        ];
    }

    #[DataProvider('unchangedProvider')]
    public function testTheShapesThatAlreadyWorkedAreUntouched(string $html, string $written): void
    {
        $this->assertSame($written, $this->carve($html));
    }

    /**
     * AND THE KEPT FIGURE REALLY IS A FIGURE. The `^` line only means anything
     * if it binds, so the two rows above that keep the caption slot are read
     * back rather than compared as text.
     */
    public function testTheKeptCaptionStillBinds(): void
    {
        $this->assertStringContainsString(
            '<figcaption>cap</figcaption>',
            $this->rendered('<figure><p><img src="i.png" alt="a"></p><figcaption>cap</figcaption></figure>'),
        );
    }
}
