<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Carve has seven task states and GFM has three, so four spellings a Markdown
 * source cannot mean as a checkbox imported as one (carve-php#2377).
 *
 * `task_state = ' ' | 'x' | 'X' | '-' | '_' | '>' | '?'` (PART 3,
 * `resources/spec/03-blocks-core.ebnf`), while cmark-gfm's task-list extension
 * accepts ` `, `x` and `X` and nothing else. This is a SET DIFFERENCE rather
 * than the extension's reach, which is why carve-php#2366 left it alone: that
 * rule counts the container markers on the line, and these four diverge at every
 * position, in scope and out.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13 through the spec repo's oracle, the
 * reader the importers answer to (markup-carve/carve#2187). commonmark 0.31.2
 * abstains, having no task list.
 */
final class TheFourCarveOnlyTaskStatesAreTextOnImportTest extends TestCase
{
    /**
     * Every Carve-only state against every bullet position the extension reaches.
     *
     * @return array<string, array{string, string}>
     */
    public static function carveOnlyStateProvider(): array
    {
        $cases = [];
        foreach (['-', '_', '>', '?'] as $state) {
            $shown = $state === '>' ? '&gt;' : $state;
            $cases['a dash bullet holds [' . $state . '] as text'] = [
                '- [' . $state . "] foo\n",
                '<ul><li>[' . $shown . '] foo</li></ul>',
            ];
            $cases['a star bullet holds [' . $state . '] as text'] = [
                '* [' . $state . "] foo\n",
                '<ul><li>[' . $shown . '] foo</li></ul>',
            ];
            $cases['a plus bullet holds [' . $state . '] as text'] = [
                '+ [' . $state . "] foo\n",
                '<ul><li>[' . $shown . '] foo</li></ul>',
            ];
            // Indentation is not a second marker, so this position reads a box
            // for the three states GFM has - and none for these four.
            $cases['an indented bullet holds [' . $state . '] as text'] = [
                '   - [' . $state . "] foo\n",
                '<ul><li>[' . $shown . '] foo</li></ul>',
            ];
            $cases['a sublist on its own line holds [' . $state . '] as text'] = [
                "- a\n  - [" . $state . "] foo\n",
                '<ul><li>a <ul><li>[' . $shown . '] foo</li></ul></li></ul>',
            ];
        }

        return $cases;
    }

    #[DataProvider('carveOnlyStateProvider')]
    public function testACarveOnlyStateImportsAsText(string $markdown, string $html): void
    {
        // The rendered HTML, because a bracket pair kept as text and a checkbox
        // are hard to tell apart in the Carve and unmistakable here.
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * The three states GFM does have, at the same positions. Without these,
     * escaping the whole state class passes the ticket.
     *
     * @return array<string, array{string, string}>
     */
    public static function gfmStateProvider(): array
    {
        return [
            'an unchecked box' => [
                "- [ ] foo\n",
                '<ul><li><input type="checkbox" disabled> foo</li></ul>',
            ],
            'a checked box' => [
                "- [x] foo\n",
                '<ul><li><input type="checkbox" checked disabled> foo</li></ul>',
            ],
            'a capital state' => [
                "* [X] foo\n",
                '<ul><li><input type="checkbox" checked disabled> foo</li></ul>',
            ],
            'an indented bullet' => [
                "   - [ ] foo\n",
                '<ul><li><input type="checkbox" disabled> foo</li></ul>',
            ],
            'a sublist on its own line' => [
                "- a\n  - [x] foo\n",
                '<ul><li>a <ul><li><input type="checkbox" checked disabled> foo</li></ul></li></ul>',
            ],
        ];
    }

    #[DataProvider('gfmStateProvider')]
    public function testAGfmStateStillImportsACheckbox(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * Where the escape lands, on the Carve the importer writes.
     *
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'a Carve-only state takes one backslash' => [
                "- [-] foo\n",
                "- \\[-] foo\n",
            ],
            'an underscore state takes one backslash' => [
                "- [_] foo\n",
                "- \\[_] foo\n",
            ],
            'the sublist position takes it too' => [
                "- a\n  - [?] foo\n",
                "- a\n  - \\[?] foo\n",
            ],
            // Out of the extension's reach the pair was already escaped for the
            // marker count (carve-php#2366), and one backslash is all it takes.
            'a quoted Carve-only state takes one' => [
                "> - [>] foo\n",
                "> - \\[>] foo\n",
            ],
            // Behind an ORDERED marker Carve reads no box, so a backslash would
            // guard nothing.
            'an ordered Carve-only state takes none' => [
                "1. [-] foo\n",
                "1. [-] foo\n",
            ],
            'a GFM state takes none' => [
                "- [x] foo\n",
                "- [x] foo\n",
            ],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testWhereTheEscapeLands(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * A position correctly read as text gains NO report row: nothing is lost
     * there, and a row would name a loss that did not happen.
     */
    public function testACarveOnlyStateReportsNothing(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("- [-] foo\n- [?] bar\n");
        $codes = array_values(array_filter(
            array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics),
            static fn (string $code): bool => $code !== 'fidelity-unverified',
        ));

        $this->assertSame([], $codes);
    }

    /**
     * WRITTEN Carve is untouched by this rule, which is the importer's. A hand
     * written `- [-] foo` keeps its box.
     */
    public function testWrittenCarveKeepsTheCarveOnlyState(): void
    {
        $html = (new CarveConverter())->convert("- [-] foo\n");

        $this->assertStringContainsString('<input type="checkbox"', $html);
        $this->assertStringContainsString('data-task-state="-"', $html);
    }

    /**
     * The escape the importer writes is what `carve fmt` writes, so an imported
     * document is already formatted.
     */
    public function testTheEscapedPairIsWhatTheFormatterWrites(): void
    {
        $carve = (new MarkdownToCarve())->convert("- [-] a\n- [?] b\n");

        $this->assertSame("- \\[-] a\n- \\[?] b\n", $carve);
        $this->assertSame($carve, CarveConverter::toCarve($carve));
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
