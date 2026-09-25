<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `CARVE-P9-074` (markup-carve/carve#2273) rules that a Markdown label which is
 * both a GFM task checkbox and a defined link reference label is the CHECKBOX.
 * The importers answer to cmark-gfm 0.29.0.gfm.13 (markup-carve/carve#2187), not
 * to GitHub's own reader, and the definition goes unused.
 *
 * The importer rewrote the pair into a collapsed reference - `- [x][] done` -
 * which renders a link where the source has a box (carve-php#2379,
 * carve-php#2382). Leaving the line alone was right: `- [x] done` with the
 * definition beneath it already reads as a box through this engine's own parser,
 * since Carve has no shortcut reference link (PART 9 section 14).
 *
 * An unused definition renders nothing, so writing its line through or omitting
 * it are both faithful; keeping the author's bytes is preferable and this engine
 * does.
 *
 * ONLY `[x]` AND `[X]` CAN COLLIDE. A link label needs a character that is not
 * whitespace, so `[ ]: /u` names no definition and the unchecked state has no
 * collision to rule on.
 *
 * OUT of the extension's reach the pair is an ordinary bracket pair, so cmark-gfm
 * resolves it as a shortcut reference and reads a LINK. The collapsed form is
 * right there, and it hides the pair from Carve's task reader at the same time.
 */
final class ATaskLabelThatIsAlsoAReferenceDefinitionReadsAsTheCheckboxTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function checkboxProvider(): array
    {
        return [
            'the bare form' => [
                "- [x] done\n\n[x]: /u\n",
                '<ul><li><input type="checkbox" checked disabled> done</li></ul>',
            ],
            'the titled form' => [
                "- [x] done\n\n[x]: /u \"T\"\n",
                '<ul><li><input type="checkbox" checked disabled> done</li></ul>',
            ],
            // The label match folds case, so an upper-case state finds the
            // lower-case definition - and is still the checkbox.
            'an upper-case label against a lower-case definition' => [
                "- [X] done\n\n[x]: /u\n",
                '<ul><li><input type="checkbox" checked disabled> done</li></ul>',
            ],
            // A tab separates the marker from its content for cmark-gfm, which
            // strips the run as the paragraph's own.
            'the tab-separated form' => [
                "- [x]\tdone\n\n[x]: /u\n",
                '<ul><li><input type="checkbox" checked disabled> done</li></ul>',
            ],
            'a star bullet' => [
                "* [x] done\n\n[x]: /u\n",
                '<ul><li><input type="checkbox" checked disabled> done</li></ul>',
            ],
            'a sublist opened on its own line' => [
                "- a\n  - [x] done\n\n[x]: /u\n",
                '<ul><li>a <ul><li><input type="checkbox" checked disabled> done</li></ul></li></ul>',
            ],
        ];
    }

    #[DataProvider('checkboxProvider')]
    public function testTheLabelReadsAsTheCheckbox(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * The author's bytes, including the definition line the reading leaves
     * unused.
     *
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'the line is left alone' => [
                "- [x] done\n\n[x]: /u\n",
                "- [x] done\n\n[x]: /u\n",
            ],
            'the title survives with it' => [
                "- [x] done\n\n[x]: /u \"T\"\n",
                "- [x] done\n\n[x]: /u \"T\"\n",
            ],
            // Carve's task marker takes a literal space, so the tab is written as
            // one and every character cmark-gfm read survives.
            'a tab becomes the space Carve reads' => [
                "- [x]\tdone\n\n[x]: /u\n",
                "- [x] done\n\n[x]: /u\n",
            ],
            // Out of the extension's reach cmark-gfm reads the link, so the
            // collapsed form is the faithful answer rather than an escape.
            'a quoted pair keeps the collapsed reference' => [
                "> - [x] done\n\n[x]: /u\n",
                "> - [x][] done\n\n[x]: /u\n",
            ],
            'a Carve-only state keeps the collapsed reference' => [
                "- [-] done\n\n[-]: /u\n",
                "- [-][] done\n\n[-]: /u\n",
            ],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testWhatTheImporterWrites(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * Out of the extension's reach the definition is USED, which is the pair this
     * rule turns on: the same two lines mean a box at one position and a link at
     * the next.
     *
     * @return array<string, array{string, string}>
     */
    public static function linkProvider(): array
    {
        return [
            'a quoted list' => [
                "> - [x] done\n\n[x]: /u\n",
                '<blockquote><ul><li><a href="/u">x</a> done</li></ul></blockquote>',
            ],
            'a list a list item holds' => [
                "- - [x] done\n\n[x]: /u\n",
                '<ul><li><ul><li><a href="/u">x</a> done</li></ul></li></ul>',
            ],
            'a Carve-only state at a top-level bullet' => [
                "- [-] done\n\n[-]: /u\n",
                '<ul><li><a href="/u">-</a> done</li></ul>',
            ],
        ];
    }

    #[DataProvider('linkProvider')]
    public function testOutOfScopeTheDefinitionIsUsed(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * THE ORDERED FORM took the same rewrite. cmark-gfm reads a box there too,
     * and Carve has no ordered task marker to write it as, so the characters
     * survive as text and the report carries the loss - not a link.
     */
    public function testTheOrderedFormKeepsItsBracketTextAndReportsTheLoss(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("1. [x] done\n\n[x]: /u\n");

        $this->assertSame("1. [x] done\n\n[x]: /u\n", $result->value);
        $this->assertSame(
            '<ol><li>[x] done</li></ol>',
            $this->render("1. [x] done\n\n[x]: /u\n"),
        );
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics);
        $this->assertContains('structure-unspellable', $codes);
    }

    /**
     * A box the definition never reaches reports nothing: the definition going
     * unused is the ruling, not a loss.
     */
    public function testTheCheckboxReportsNothing(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("- [x] done\n\n[x]: /u\n");
        $codes = array_values(array_filter(
            array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics),
            static fn (string $code): bool => $code !== 'fidelity-unverified',
        ));

        $this->assertSame([], $codes);
    }

    /**
     * A definition NOT named by a task label is still written in the form Carve
     * reads, so the collapsed rewrite is narrowed rather than switched off.
     */
    public function testAnOrdinaryShortcutReferenceStillCollapses(): void
    {
        $this->assertSame(
            "- see [r][] here\n\n[r]: /u\n",
            (new MarkdownToCarve())->convert("- see [r] here\n\n[r]: /u\n"),
        );
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
