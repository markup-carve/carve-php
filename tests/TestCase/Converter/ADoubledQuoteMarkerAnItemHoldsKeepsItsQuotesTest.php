<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A doubled quote marker a list item holds keeps its quotes. The markers were
 * neither respelled in Carve's spaced form nor escaped, so Carve read them as
 * text and both quotes went missing from the document (carve-php#2341).
 *
 * The top level was never affected: `>> foo` is respelled `> > foo` on its way
 * in. An item writes its quote from its own branch, which never reached the
 * respelling, and `normalizeBlockquoteMarkers` starts at the first byte, so the
 * indentation holding the markers put them out of its reach.
 *
 * PINNED AT THE RENDERED LEVEL: the converter corpus compares an importer by
 * rendering its output, and the visible `&gt;&gt;` is what was wrong.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13, the reader the importers answer to
 * (carve#2187). carve-js `d8ca1cd` writes the same broken line, so it is no
 * oracle here; filed as markup-carve/carve-js#2035.
 */
final class ADoubledQuoteMarkerAnItemHoldsKeepsItsQuotesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function quotesProvider(): array
    {
        return [
            'a bullet item' => [
                "- >> foo\n  >> bar\n",
                '<ul><li><blockquote><blockquote><p>foo bar</p></blockquote></blockquote></li></ul>',
            ],
            'an ordered item' => [
                "1. >> foo\n   >> bar\n",
                '<ol><li><blockquote><blockquote><p>foo bar</p></blockquote></blockquote></li></ol>',
            ],
            'three markers deep' => [
                "- >>> foo\n  >>> bar\n",
                '<ul><li><blockquote><blockquote><blockquote><p>foo bar</p>'
                    . '</blockquote></blockquote></blockquote></li></ul>',
            ],
            'two items deep' => [
                "- - >> foo\n    >> bar\n",
                '<ul><li><ul><li><blockquote><blockquote><p>foo bar</p>'
                    . '</blockquote></blockquote></li></ul></li></ul>',
            ],
            // The break line is written by a branch of its own, which respelled
            // the markers only when nothing indented them.
            'a thematic break the doubled quote holds' => [
                "- >> foo\n  >> bar\n  >> - - -\n",
                '<ul><li><blockquote><blockquote><p>foo bar</p><hr></blockquote></blockquote></li></ul>',
            ],
            'a star break the doubled quote holds' => [
                "- >> foo\n  >> ***\n",
                '<ul><li><blockquote><blockquote><p>foo</p><hr></blockquote></blockquote></li></ul>',
            ],
            'a heading the doubled quote holds' => [
                "- >> foo\n  >> # bar\n",
                '<ul><li><blockquote><blockquote><p>foo</p><h1>bar</h1></blockquote></blockquote></li></ul>',
            ],
        ];
    }

    #[DataProvider('quotesProvider')]
    public function testTheItemHeldQuotesSurvive(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * What held before the change and holds after it.
     *
     * @return array<string, array{string, string}>
     */
    public static function controlsProvider(): array
    {
        return [
            'a doubled marker at the top level' => [
                ">> foo\n>> bar\n",
                '<blockquote><blockquote><p>foo bar</p></blockquote></blockquote>',
            ],
            'a single marker an item holds' => [
                "- > foo\n  > bar\n",
                '<ul><li><blockquote><p>foo bar</p></blockquote></li></ul>',
            ],
            'an already spaced marker an item holds' => [
                "- > > foo\n  > > bar\n",
                '<ul><li><blockquote><blockquote><p>foo bar</p></blockquote></blockquote></li></ul>',
            ],
            // The quoted setext fold respelled the prefix on its way out even
            // before this change, which is what made the defect look narrower
            // than it was: the same line came back correct when it happened to
            // end in an underline.
            'a doubled quote that folds' => [
                "- >> foo\n  >>     # bar\n  >> ---\n",
                '<ul><li><blockquote><blockquote><h2>foo # bar</h2></blockquote></blockquote></li></ul>',
            ],
            'a break an item holds with no quote' => [
                "- foo\n  - - -\n",
                '<ul><li>foo <hr></li></ul>',
            ],
        ];
    }

    #[DataProvider('controlsProvider')]
    public function testWhatAlreadyHeldStillHolds(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    private function render(string $markdown): string
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $html = (new CarveConverter())->convert($carve);

        return trim((string)preg_replace(['/\s+id="[^"]*"/', '/>\s+</', '/\s+/'], ['', '><', ' '], $html));
    }
}
