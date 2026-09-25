<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GFM's task-list extension takes a checkbox off a line carrying ONE marker and
 * whitespace before it. A second container marker on that line - another item's,
 * or a quote's - puts the list out of its reach and the bracket pair is text.
 * Carve's task item has no such restriction, so `> - [ ] foo` and `- - [ ] foo`
 * imported a checkbox the reader has none of (carve-php#2366).
 *
 * THE OTHER HALF OF THAT TICKET IS UNSPELLABLE. cmark-gfm does read a box on
 * `1. [x] done`, but `task_marker` is reachable from `unordered_item` alone
 * (PART 3), so Carve has no ordered task item to write. The characters survive
 * as text and the fidelity report carries the loss; see
 * `AnOrderedTaskItemIsNotSpellableTest`.
 *
 * The rule here is the extension's SCOPE, not its state set. The four
 * Carve-only states (`[-]`, `[_]`, `[>]`, `[?]`) diverge at every position, in
 * scope or out, which is a different question and a separate ticket.
 *
 * PINNED AT THE RENDERED LEVEL, since the converter corpus compares an importer
 * by rendering its output.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13, the reader the importers answer to
 * (markup-carve/carve#2187). commonmark 0.31.2 abstains, having no task list.
 */
final class ATaskCheckboxOutsideTheExtensionsScopeIsTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function outOfScopeProvider(): array
    {
        return [
            // The ticket's two cases.
            'a quoted list' => [
                "> - [ ] foo\n",
                '<blockquote><ul><li>[ ] foo</li></ul></blockquote>',
            ],
            'a list a list item holds' => [
                "- - [ ] foo\n",
                '<ul><li><ul><li>[ ] foo</li></ul></li></ul>',
            ],
            'a quoted list, checked' => [
                "> - [x] foo\n",
                '<blockquote><ul><li>[x] foo</li></ul></blockquote>',
            ],
            'a star bullet in a quote' => [
                "> * [ ] foo\n",
                '<blockquote><ul><li>[ ] foo</li></ul></blockquote>',
            ],
            'a bullet an ordered item holds' => [
                "1. - [ ] foo\n",
                '<ol><li><ul><li>[ ] foo</li></ul></li></ol>',
            ],
            'three markers deep' => [
                "- - - [ ] foo\n",
                '<ul><li><ul><li><ul><li>[ ] foo</li></ul></li></ul></li></ul>',
            ],
            'a doubly quoted list' => [
                "> > - [ ] foo\n",
                '<blockquote><blockquote><ul><li>[ ] foo</li></ul></blockquote></blockquote>',
            ],
            // The list is the quote's SECOND block here, so the position is not
            // "first thing in the container" - the marker count is what decides.
            'a quoted list under a quoted paragraph' => [
                "> a\n>\n> - [ ] foo\n",
                '<blockquote><p>a</p><ul><li>[ ] foo</li></ul></blockquote>',
            ],
            'a second item of a quoted list' => [
                "> - a\n> - [ ] foo\n",
                '<blockquote><ul><li>a</li><li>[ ] foo</li></ul></blockquote>',
            ],
            'every item of a quoted list' => [
                "> - [ ] a\n> - [x] b\n",
                '<blockquote><ul><li>[ ] a</li><li>[x] b</li></ul></blockquote>',
            ],
            // A tab between the two markers is still two markers.
            'a tab between the two markers' => [
                "-\t- [ ] foo\n",
                '<ul><li><ul><li>[ ] foo</li></ul></li></ul>',
            ],
            // The pair is text, so the line below it continues that text rather
            // than a task item's paragraph.
            'a lazy line under a quoted pair' => [
                "> - [ ] foo\n> bar\n",
                '<blockquote><ul><li>[ ] foo bar</li></ul></blockquote>',
            ],
        ];
    }

    #[DataProvider('outOfScopeProvider')]
    public function testTheBracketPairStaysText(string $markdown, string $html): void
    {
        // Element AND text: a checkbox beside the same words reads as if nothing
        // went wrong, so an absence assertion proves nothing here.
        $this->assertSame($html, $this->render($markdown));
        $this->assertSame($html, $this->render($markdown, SmartTypographyMode::Source));
    }

    /**
     * Where the extension DOES reach. Without these a fix that simply stopped
     * reading checkboxes would pass the out-of-scope half.
     *
     * @return array<string, array{string, string}>
     */
    public static function inScopeProvider(): array
    {
        return [
            'a top-level bullet' => [
                "- [ ] foo\n",
                '<ul><li><input type="checkbox" disabled> foo</li></ul>',
            ],
            'a top-level bullet, checked' => [
                "- [x] foo\n",
                '<ul><li><input type="checkbox" checked disabled> foo</li></ul>',
            ],
            'a star bullet' => [
                "* [X] foo\n",
                '<ul><li><input type="checkbox" checked disabled> foo</li></ul>',
            ],
            // Whitespace before the marker is not a second marker, so an
            // indented top-level list keeps its box.
            'three columns of indentation' => [
                "   - [ ] foo\n",
                '<ul><li><input type="checkbox" disabled> foo</li></ul>',
            ],
            // A sublist that opens on its OWN line carries one marker on that
            // line, so the extension reaches it where `- - [ ] foo` puts it out
            // of reach. This is the pair the marker count tells apart.
            'a sublist opened on its own line' => [
                "- a\n  - [ ] foo\n",
                '<ul><li>a <ul><li><input type="checkbox" disabled> foo</li></ul></li></ul>',
            ],
            'a bullet after a paragraph' => [
                "x\n\n- [ ] foo\n",
                '<p>x</p><ul><li><input type="checkbox" disabled> foo</li></ul>',
            ],
        ];
    }

    #[DataProvider('inScopeProvider')]
    public function testTheCheckboxInScopeSurvives(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
        $this->assertSame($html, $this->render($markdown, SmartTypographyMode::Source));
    }

    /**
     * What held before the change and holds after it.
     *
     * @return array<string, array{string, string}>
     */
    public static function controlsProvider(): array
    {
        return [
            'a quoted list with no bracket pair' => [
                "> - foo\n",
                '<blockquote><ul><li>foo</li></ul></blockquote>',
            ],
            'a quoted list whose brackets sit inside its text' => [
                "> - a [ ] b\n",
                '<blockquote><ul><li>a [ ] b</li></ul></blockquote>',
            ],
            'a nested list with no bracket pair' => [
                "- - foo\n",
                '<ul><li><ul><li>foo</li></ul></li></ul>',
            ],
            // Not a task state in either reader, so nothing needs escaping.
            'a two-character state in a quote' => [
                "> - [xx] foo\n",
                '<blockquote><ul><li>[xx] foo</li></ul></blockquote>',
            ],
            'an empty bracket pair in a quote' => [
                "> - [] foo\n",
                '<blockquote><ul><li>[] foo</li></ul></blockquote>',
            ],
            'a pair with no space after it in a quote' => [
                "> - [ ]foo\n",
                '<blockquote><ul><li>[ ]foo</li></ul></blockquote>',
            ],
        ];
    }

    #[DataProvider('controlsProvider')]
    public function testWhatAlreadyHeldStillHolds(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
        $this->assertSame($html, $this->render($markdown, SmartTypographyMode::Source));
    }

    /**
     * Where the escape lands, asserted on the Carve the importer WRITES.
     *
     * A decorative escape is invisible once rendered, so the render assertions
     * cannot tell a narrow gate from a wide one. These can: every backslash
     * below changes the parse, and every line without one would gain nothing
     * from having it.
     *
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'the pair takes one backslash in a quote' => [
                "> - [ ] foo\n",
                "> - \\[ ] foo\n",
            ],
            'the pair takes one backslash in a nested list' => [
                "- - [x] foo\n",
                "- - \\[x] foo\n",
            ],
            'a top-level pair takes none' => [
                "- [ ] foo\n",
                "- [ ] foo\n",
            ],
            // Carve reads a checkbox off a BULLET only, so a quoted ordered item
            // already holds the pair as text. A backslash here would guard
            // nothing.
            'a quoted ordered item takes none' => [
                "> 1. [ ] foo\n",
                "> 1. [ ] foo\n",
            ],
            'an ordered item a list item holds takes none' => [
                "- 1. [ ] foo\n",
                "- 1. [ ] foo\n",
            ],
            // The scope rule reads the marker count, not the state set, so a
            // Carve-only state in scope is left where it stands.
            'a Carve-only state at a top-level bullet takes none' => [
                "- [-] foo\n",
                "- [-] foo\n",
            ],
            'a Carve-only state out of scope takes the escape with the rest' => [
                "> - [-] foo\n",
                "> - \\[-] foo\n",
            ],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testWhereTheEscapeLands(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * The escape the importer writes is what `carve fmt` writes, so an imported
     * document is already formatted.
     */
    public function testTheEscapedPairIsWhatTheFormatterWrites(): void
    {
        $carve = (new MarkdownToCarve())->convert("> - [ ] a\n> - [x] b\n");

        $this->assertSame("> - \\[ ] a\n> - \\[x] b\n", $carve);
        $this->assertSame($carve, CarveConverter::toCarve($carve));
    }

    private function render(string $markdown, ?SmartTypographyMode $typography = null): string
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $converter = new CarveConverter();
        $renderer = $converter->getRenderer();
        if ($typography !== null && $renderer instanceof HtmlRenderer) {
            $renderer->setSmartTypography($typography);
        }
        $html = $converter->convert($carve);

        return trim((string)preg_replace(
            ['/\s+id="[^"]*"/', '/\s+aria-label="[^"]*"/', '/<\/?section>/', '/>\s+</', '/\s+/'],
            ['', '', '', '><', ' '],
            $html,
        ));
    }
}
