<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A nested item that opens on an item's CONTINUATION line - no blank line above
 * it - is written by a branch of its own, and that branch applied none of the
 * task-line readings the rest of the importer has. Found by enumerating the
 * positions the GFM task-list extension reaches while ruling carve-php#2377,
 * carve-php#2381 and carve-php#2382.
 *
 * Three rules apply there as they do after a blank line.
 *
 * The extension's SCOPE by marker count (carve-php#2366), so `- - [ ] b` nested on
 * such a line holds the pair as text. The loss an ordered task item forces
 * (carve-php#2381), so `1. [ ] b` there keeps its bracket text and the report says
 * the box is gone. And the item's own text after its box (carve-php#2343), so
 * `- [ ] > b` holds a `>` rather than opening a quote.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13 through the spec repo's oracle
 * (markup-carve/carve#2187).
 */
final class ATaskLineOnAnItemsContinuationTakesTheSameReadingsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function continuationProvider(): array
    {
        return [
            // Two markers on the line, so the extension does not reach it.
            'a list a continuation item holds' => [
                "- a\n  - - [ ] foo\n",
                '<ul><li>a <ul><li><ul><li>[ ] foo</li></ul></li></ul></li></ul>',
            ],
            'a quoted list on a continuation line' => [
                "- a\n  > - [x] foo\n",
                '<ul><li>a <blockquote><ul><li>[x] foo</li></ul></blockquote></li></ul>',
            ],
            // A Carve-only state, which diverges for the state set rather than the
            // marker count.
            'a Carve-only state on a continuation line' => [
                "- a\n  - [-] foo\n",
                '<ul><li>a <ul><li>[-] foo</li></ul></li></ul>',
            ],
            'a Carve-only state two levels down' => [
                "- a\n  - b\n    - [?] foo\n",
                '<ul><li>a <ul><li>b <ul><li>[?] foo</li></ul></li></ul></li></ul>',
            ],
            // A block marker after the box is the item's text for cmark-gfm,
            // whose extension takes a checkbox off a PARAGRAPH.
            'a quote marker after the box' => [
                "- a\n  - [ ] > foo\n",
                '<ul><li>a <ul><li><input type="checkbox" disabled> &gt; foo</li></ul></li></ul>',
            ],
            'a heading marker after the box' => [
                "- a\n  - [ ] # foo\n",
                '<ul><li>a <ul><li><input type="checkbox" disabled> # foo</li></ul></li></ul>',
            ],
            'a tilde fence after the box' => [
                "- a\n  - [ ] ~~~\n",
                '<ul><li>a <ul><li><input type="checkbox" disabled> ~~~</li></ul></li></ul>',
            ],
            // A tab between the marker and the content leaves Carve no box, so
            // the importer writes the space it reads.
            'a tab after the box on a continuation line' => [
                "- a\n  - [x]\tdone\n",
                '<ul><li>a <ul><li><input type="checkbox" checked disabled> done</li></ul></li></ul>',
            ],
        ];
    }

    #[DataProvider('continuationProvider')]
    public function testTheContinuationLineReadsAsTheOracleDoes(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * Where the escape lands on the continuation line.
     *
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'a second marker takes the escape' => [
                "- a\n  - - [ ] foo\n",
                "- a\n  - - \\[ ] foo\n",
            ],
            'a Carve-only state takes the escape' => [
                "- a\n  - [-] foo\n",
                "- a\n  - \\[-] foo\n",
            ],
            'a block marker after the box takes it' => [
                "- a\n  - [ ] > foo\n",
                "- a\n  - [ ] \\> foo\n",
            ],
            'a tab becomes the space Carve reads' => [
                "- a\n  - [x]\tdone\n",
                "- a\n  - [x] done\n",
            ],
            // A box the extension does reach is left exactly as it stands.
            'a box in scope takes none' => [
                "- a\n  - [x] foo\n",
                "- a\n  - [x] foo\n",
            ],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testWhereTheEscapeLands(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * An ORDERED task item on a continuation line keeps its bracket text and the
     * report names the box Carve cannot spell, which is what the same shape after
     * a blank line already did.
     */
    public function testAnOrderedContinuationItemReportsItsLostBox(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("- a\n  1. [x] foo\n");

        $this->assertSame("- a\n  1. [x] foo\n", $result->value);
        $this->assertSame(
            '<ul><li>a <ol><li>[x] foo</li></ol></li></ul>',
            $this->render("- a\n  1. [x] foo\n"),
        );
        $rows = array_values(array_filter(
            $result->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === 'structure-unspellable',
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('line:2', $rows[0]->path);
    }

    /**
     * A position correctly read as text gains NO row: nothing is lost where both
     * readers hold the pair as text.
     */
    public function testAPairHeldAsTextReportsNothing(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("- a\n  - - [ ] foo\n  - [-] bar\n");
        $codes = array_values(array_filter(
            array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics),
            static fn (string $code): bool => $code !== 'fidelity-unverified',
        ));

        $this->assertSame([], $codes);
    }

    /**
     * The controls: a continuation item with no bracket pair, and a box the
     * extension reaches, are both untouched.
     *
     * @return array<string, array{string, string}>
     */
    public static function controlsProvider(): array
    {
        return [
            'a continuation sublist with no pair' => [
                "- a\n  - foo\n",
                "- a\n  - foo\n",
            ],
            'a nested list with no pair' => [
                "- a\n  - - foo\n",
                "- a\n  - - foo\n",
            ],
            'brackets inside the text' => [
                "- a\n  - b [ ] c\n",
                "- a\n  - b [ ] c\n",
            ],
            'a pair with no space after it' => [
                "- a\n  - [ ]foo\n",
                "- a\n  - [ ]foo\n",
            ],
        ];
    }

    #[DataProvider('controlsProvider')]
    public function testWhatAlreadyHeldStillHolds(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    private function render(string $markdown): string
    {
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        return trim((string)preg_replace(
            ['/\s+id="[^"]*"/', '/\s+aria-label="[^"]*"/', '/<\/?section>/', '/>\s+</', '/\s+/'],
            ['', '', '', '><', ' '],
            $html,
        ));
    }
}
