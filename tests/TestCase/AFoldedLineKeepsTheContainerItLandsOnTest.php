<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A LAZY LINE FOLDED INTO AN ITEM MAY NOT ENTER A CONTAINER OPENER.
 *
 * PART 0 §4's lazy line is paragraph text, and a collector below the item's
 * content column folds it into the entry above. Where that entry OPENED a
 * container the fold is not text at all: the container's body is re-read line
 * by line from its opener, so a folded `- > - x` handed the quote one "line"
 * holding `> - x\n[r]: /url`, the sub-list inside it was never seen, and its
 * marker came back as literal paragraph text (markup-carve/carve-php#1858).
 *
 * WHAT DECIDED IT WAS THE SHAPE OF THE FOLDED LINE, NOT ITS CONTENT. A prose
 * line takes the collector's own-entry branch and the quote survived; a line
 * shaped like a block opener took the fold branch and destroyed it. So the
 * lines that carry the rule here are the three opener kinds, and the prose line
 * is the control that was already right.
 *
 * STATED OVER BLOCK OPENERS, NOT OVER DEFINITIONS, for the reason
 * markup-carve/carve-php#1857 gives: a definition, a heading and a thematic
 * break diverged identically, and a definition-only rule would leave a heading
 * folding where a definition no longer does.
 *
 * The controls are the other half. Folding onto a plain paragraph entry is
 * untouched, a quote whose body is a paragraph still takes the folded line into
 * that paragraph rather than opening anything, and the same document without
 * the outer item - the one the fold never runs for - is unchanged.
 */
class AFoldedLineKeepsTheContainerItLandsOnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * The block-opener shapes that took the fold branch.
     *
     * @return array<string, array{0: string}>
     */
    public static function openerProvider(): array
    {
        return [
            'a link reference definition' => ['[r]: /url'],
            'a heading' => ['# H'],
            'a thematic break' => ['---'],
        ];
    }

    /**
     * The reported document, pinned whole: `- > - x` over an opener at column 1.
     */
    #[DataProvider('openerProvider')]
    public function testTheQuoteLeadItemKeepsItsInnerList(string $opener): void
    {
        $html = $this->converter->convert("- > - x\n " . $opener . "\n");

        $this->assertSame(
            '<ul><li><blockquote><ul><li>x' . "\n" . self::foldedText($opener) . '</li></ul></blockquote></li></ul>',
            self::squeeze($html),
        );
    }

    /**
     * Every prefix in which a list item's lead is a quote, at the column that
     * reaches nothing.
     *
     * The value is the container census the prefix owes: one `<ul>` per `l` and
     * one `<blockquote>` per `q`, all of them still standing over the folded
     * line. Before the fix the fold ate one `<ul>` in every row here, and in
     * `- > - > x` a `<blockquote>` with it.
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function quoteLeadPrefixProvider(): array
    {
        return [
            '- > - x' => ['- > - x', 2, 1],
            '- - > - x' => ['- - > - x', 3, 1],
            '- > - - x' => ['- > - - x', 3, 1],
            '- > - > x' => ['- > - > x', 2, 2],
            '- > > - x' => ['- > > - x', 2, 2],
            '> - > - x' => ['> - > - x', 2, 2],
        ];
    }

    #[DataProvider('quoteLeadPrefixProvider')]
    public function testEveryContainerInTheLeadSurvivesTheFold(string $lead, int $lists, int $quotes): void
    {
        foreach (self::openerProvider() as $case) {
            $html = $this->converter->convert($lead . "\n " . $case[0] . "\n");

            $this->assertSame($lists, substr_count($html, '<ul>'), $lead . ' over ' . $case[0]);
            $this->assertSame($quotes, substr_count($html, '<blockquote>'), $lead . ' over ' . $case[0]);
            $this->assertStringNotContainsString('<p>- ', $html, 'a marker came back as literal text');
        }
    }

    /**
     * Every column `> - > - x` makes live, and the frame base below them all.
     *
     * The fold ran at all ten columns below the innermost content column, so
     * this is where a fix that only moved the boundary rather than repairing
     * the fold shows up. Column 0 is the control on the other side: at the
     * frame's own base the opener is a block, not a folded line.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function alternatingPrefixColumnProvider(): array
    {
        $cases = ['the frame base' => [0, true]];
        for ($column = 1; $column <= 10; $column++) {
            $cases['column ' . $column] = [$column, false];
        }

        return $cases;
    }

    #[DataProvider('alternatingPrefixColumnProvider')]
    public function testTheAlternatingPrefixKeepsItsContainersAtEveryColumn(int $column, bool $opens): void
    {
        $html = $this->converter->convert("> - > - x\n" . str_repeat(' ', $column) . "# H\n");

        $this->assertSame(2, substr_count($html, '<ul>'));
        $this->assertSame(2, substr_count($html, '<blockquote>'));
        $this->assertSame($opens, str_contains($html, '<h1'));
    }

    /**
     * The fold is not the lead's privilege - a container opened by a later line
     * of the item is destroyed the same way.
     */
    #[DataProvider('openerProvider')]
    public function testAContainerOpenedBelowTheLeadSurvivesTheFold(string $opener): void
    {
        $html = $this->converter->convert("- a\n  > - q\n " . $opener . "\n");

        $this->assertSame(
            '<ul><li>a<blockquote><ul><li>q' . "\n" . self::foldedText($opener) . '</li></ul></blockquote></li></ul>',
            self::squeeze($html),
        );
    }

    /**
     * CONTROL: a prose lazy line was always folded correctly, and still is.
     */
    public function testAProseLazyLineIsUnchanged(): void
    {
        $html = $this->converter->convert("- > - x\n y\n");

        $this->assertSame('<ul><li><blockquote><ul><li>x' . "\n" . 'y</li></ul></blockquote></li></ul>', self::squeeze($html));
    }

    /**
     * CONTROL: a quote whose body is a paragraph takes the folded line into
     * that paragraph. Nothing opens, and no new block appears beside it.
     */
    #[DataProvider('openerProvider')]
    public function testAQuotedParagraphStillAbsorbsTheFoldedLine(string $opener): void
    {
        $html = $this->converter->convert("- a\n  > q\n " . $opener . "\n");

        $this->assertSame(
            '<ul><li>a<blockquote><p>q' . "\n" . self::foldedText($opener) . '</p></blockquote></li></ul>',
            self::squeeze($html),
        );
    }

    /**
     * CONTROL: folding onto a plain paragraph entry is what the fold is FOR.
     */
    #[DataProvider('openerProvider')]
    public function testAPlainParagraphEntryStillTakesTheFold(string $opener): void
    {
        $html = $this->converter->convert("- a\n " . $opener . "\n");

        $this->assertSame('<ul><li>a' . "\n" . self::foldedText($opener) . '</li></ul>', self::squeeze($html));
    }

    /**
     * CONTROL: without the outer item there is no fold, and the document was
     * already right. It is the shape the fixed engine now agrees with.
     */
    #[DataProvider('openerProvider')]
    public function testTheSameQuoteWithoutAnOuterItemIsUnchanged(string $opener): void
    {
        $html = $this->converter->convert("> - x\n " . $opener . "\n");

        $this->assertSame(
            '<blockquote><ul><li>x' . "\n" . self::foldedText($opener) . '</li></ul></blockquote>',
            self::squeeze($html),
        );
    }

    /**
     * CONTROL: a folded definition is TEXT, so nothing may resolve against it.
     */
    public function testTheFoldedDefinitionDoesNotRegister(): void
    {
        $html = $this->converter->convert("- > - x\n [r]: /url\n\nSee [r][].\n");

        $this->assertStringNotContainsString('<a href="/url">', $html);
        $this->assertStringContainsString('[r]: /url', $html);
    }

    /**
     * A thematic break folded as text is inline, where typography rewrites the
     * run - so the folded bytes are not the ones that were authored.
     */
    protected static function foldedText(string $opener): string
    {
        return $opener === '---' ? "\u{2014}" : $opener;
    }

    /**
     * Collapse the pretty-printer's whitespace BEFORE a tag, leaving the
     * newline a fold puts inside text where it is - that one is followed by the
     * folded line's own first byte, never by `<`.
     */
    protected static function squeeze(string $html): string
    {
        return trim((string)preg_replace('/\s+</', '<', trim($html)));
    }
}
