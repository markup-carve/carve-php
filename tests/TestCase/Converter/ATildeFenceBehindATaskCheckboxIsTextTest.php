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
 * GFM's task-list extension lifts a checkbox out of a PARAGRAPH, so whatever
 * follows it on that line is text of the paragraph. Carve reads a fence there,
 * so `- [ ] ~~~` opened a code block the source never spelled
 * (carve-php#2356).
 *
 * The escape belongs to `escapeTaskItemOpener` rather than to
 * `escapeBlockOpener`, which the item's own continuation lines share. Measured
 * on the 8840-case matrix from carve-php#2364: widening the shared helper closes
 * the same 8 rows and breaks none, but writes 48 escapes on continuation lines
 * where nothing opens, because a Carve fence interrupts no paragraph. That is the
 * decorative escape carve-php#2339 spent a fix removing for the pipe row, and
 * `a tilde fence four columns into an item paragraph keeps its run` is the
 * control that tells the two placements apart.
 *
 * The backtick spelling was already right: a backtick run is inline-structural
 * and takes its escape from the inline pass.
 *
 * PINNED AT THE RENDERED LEVEL, since the converter corpus compares an importer
 * by rendering its output.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13, the reader the importers answer to
 * (markup-carve/carve#2187). commonmark 0.31.2 abstains, having no task list.
 */
final class ATildeFenceBehindATaskCheckboxIsTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function fenceProvider(): array
    {
        return [
            // The ticket's case. The trailing run leaves the item and opens an
            // empty fence of its own, which both readers agree on.
            'a fence and its closer' => [
                "- [ ] ~~~\nx\n~~~\n",
                '<ul><li><input type="checkbox" disabled> ~~~ x</li></ul><pre><code></code></pre>',
            ],
            'a fence alone on the line' => [
                "- [ ] ~~~\n",
                '<ul><li><input type="checkbox" disabled> ~~~</li></ul>',
            ],
            'a four-tilde run' => [
                "- [ ] ~~~~\n",
                '<ul><li><input type="checkbox" disabled> ~~~~</li></ul>',
            ],
            // An info string is text of the paragraph too, so the language never
            // reaches a code block.
            'a fence carrying an info string' => [
                "- [ ] ~~~ php\nx\n~~~\n",
                '<ul><li><input type="checkbox" disabled> ~~~ php x</li></ul><pre><code></code></pre>',
            ],
            'a run abutting its info string' => [
                "- [ ] ~~~foo\n",
                '<ul><li><input type="checkbox" disabled> ~~~foo</li></ul>',
            ],
            'behind a checked box' => [
                "- [x] ~~~\nx\n",
                '<ul><li><input type="checkbox" checked disabled> ~~~ x</li></ul>',
            ],
            'behind a star bullet' => [
                "* [ ] ~~~\nx\n",
                '<ul><li><input type="checkbox" disabled> ~~~ x</li></ul>',
            ],
        ];
    }

    #[DataProvider('fenceProvider')]
    public function testTheRunStaysParagraphText(string $markdown, string $html): void
    {
        // Element AND text: a code block holding the same characters reads as if
        // nothing went wrong, so an absence assertion proves nothing here.
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
            // Two tildes are no fence, so nothing needs escaping.
            'a two-tilde run behind a checkbox' => [
                "- [ ] ~~\n",
                '<ul><li><input type="checkbox" disabled> ~~</li></ul>',
            ],
            'a tilde run inside the text behind a checkbox' => [
                "- [ ] a ~~~ b\n",
                '<ul><li><input type="checkbox" disabled> a ~~~ b</li></ul>',
            ],
            'the backtick spelling was already text' => [
                "- [ ] ```\nx\n```\n",
                '<ul><li><input type="checkbox" disabled> ``` x</li></ul><pre><code></code></pre>',
            ],
            // A fence on a plain item's own line still opens its code block,
            // there being no checkbox to lift it out of a paragraph.
            'a fence on a plain item line still opens a block' => [
                "- ~~~\nx\n~~~\n",
                '<ul><li><pre><code></code></pre></li></ul><p>x</p><pre><code></code></pre>',
            ],
            // A fence at the item's content column after its paragraph still
            // opens a block, which is the shape the shared helper would have
            // reached.
            'a fence on a continuation line still opens a block' => [
                "- foo\n  ~~~\n  x\n  ~~~\n",
                '<ul><li>foo <pre><code>x </code></pre></li></ul>',
            ],
            // The rest of what escapeBlockOpener covers behind a checkbox, from
            // carve-php#2343, is untouched.
            'a quote marker behind a checkbox stays escaped' => [
                "- [ ] > foo\n",
                '<ul><li><input type="checkbox" disabled> &gt; foo</li></ul>',
            ],
            'a thematic break behind a checkbox stays escaped' => [
                "- [ ] ---\n",
                '<ul><li><input type="checkbox" disabled> ---</li></ul>',
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
     * above cannot tell the task-position branch from a widening of the shared
     * helper. These can.
     *
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'one backslash opens the run behind a checkbox' => [
                "- [ ] ~~~\n",
                "- [ ] \\~~~\n",
            ],
            'an info string is not escaped with it' => [
                "- [ ] ~~~ php\n",
                "- [ ] \\~~~ php\n",
            ],
            'a two-tilde run takes no escape' => [
                "- [ ] ~~\n",
                "- [ ] ~~\n",
            ],
            // Four columns past the item's content column, where a fence would
            // be indented code and a Carve fence interrupts no paragraph either
            // way. Widening escapeBlockOpener writes a backslash here that
            // guards nothing; this pins that it does not.
            'a tilde fence four columns into an item paragraph keeps its run' => [
                "- foo\n      ~~~\n",
                "- foo\n  ~~~\n",
            ],
            'a tilde fence four columns into a paragraph keeps its run' => [
                "foo\n    ~~~\n",
                "foo\n    ~~~\n",
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
    public function testTheEscapedRunIsWhatTheFormatterWrites(): void
    {
        $carve = (new MarkdownToCarve())->convert("- [ ] ~~~\nx\n");

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
