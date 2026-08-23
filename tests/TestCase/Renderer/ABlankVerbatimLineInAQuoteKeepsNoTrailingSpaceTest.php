<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 section 7, applied to the CONTAINER PREFIX a blank verbatim line
 * inherits (markup-carve/carve#1544).
 *
 * A blank line inside a code block is carried through document normalization as
 * a sentinel so the whole-document trim cannot eat it, and by the time the
 * sentinel is restored its host has already written a prefix in front of it.
 * Section 7 emits the STRUCTURAL INDENT of such a line as nothing - "when the
 * verbatim content on that line is EMPTY the indent alone is what remains --
 * that is layout, and it is omitted" - which is why the list writer already
 * emitted the blank un-indented.
 *
 * A BLOCK QUOTE'S PREFIX IS TWO THINGS AT ONCE and only one of them is layout.
 * The `>` stays: an empty line would close the quote and take the open fence
 * with it, and section 7a spells an empty quote line `>` for that reason. The
 * SPACE after it is layout, and dropping it is how this writer spells a blank
 * quote line everywhere else. Keeping it emitted `> `, a line with a trailing
 * run - the tooling hazard section 7 names, and one a whitespace-only-line
 * check cannot see because the line is not whitespace-only.
 *
 * Three core corpus documents diverged on it: carve-rs wrote `>` and this
 * writer wrote `> `.
 *
 * THE ASSERTIONS ARE ON BYTES. Section 1 forgives spelling on purpose - both
 * forms re-parse to the same tree and render the same HTML - so a round-trip
 * check cannot see the difference, which is why three engines disagreed with
 * every other gate green. The last case re-checks section 1 anyway, so the
 * spelling is not bought by changing the document.
 */
class ABlankVerbatimLineInAQuoteKeepsNoTrailingSpaceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shapes(): array
    {
        return [
            'an unterminated fence in a quote' => [
                "> ```\n",
                "> ```\n>\n> ```\n",
            ],
            'a blank line inside a fenced block in a quote' => [
                "> ```\n> x\n>\n> y\n> ```\n",
                "> ```\n> x\n>\n> y\n> ```\n",
            ],
            'a nested quote keeps every marker' => [
                "> > ```\n> > x\n> >\n> > y\n> > ```\n",
                "> > ```\n> > x\n> >\n> > y\n> > ```\n",
            ],
            'a list item inside a quote drops both runs' => [
                "> - ```\n>   x\n>\n>   y\n>   ```\n",
                "> - ```\n>   x\n>\n>   y\n>   ```\n",
            ],
            'a list item outside a quote emits the blank empty' => [
                "- ```\n  x\n\n  y\n  ```\n",
                "- ```\n  x\n\n  y\n  ```\n",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheWrittenFormSpellsTheBlankTheWayItsHostDoes(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::toCarve($source));
    }

    #[DataProvider('shapes')]
    public function testNoEmittedLineCarriesATrailingRun(string $source, string $expected): void
    {
        unset($expected);
        $offenders = array_values(array_filter(
            explode("\n", CarveConverter::toCarve($source)),
            static fn (string $line): bool => $line !== rtrim($line, " \t"),
        ));
        $this->assertSame([], $offenders, 'a line ends in a space or tab: ' . json_encode($offenders));
    }

    #[DataProvider('shapes')]
    public function testTheWrittenFormStillSaysWhatTheSourceSays(string $source, string $expected): void
    {
        unset($expected);
        $converter = new CarveConverter();
        $once = CarveConverter::toCarve($source);
        $this->assertSame($converter->convert($source), $converter->convert($once));
        $this->assertSame($once, CarveConverter::toCarve($once));
    }
}
