<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `roundtrip` mode's input is THIS ENGINE'S OWN OUTPUT by definition, so a
 * heading id there was GENERATED - and re-emitting it CHANGES THE RENDER,
 * because `HtmlRenderer` writes a generated id after every authored attribute
 * and an authored one in the slot it was written in. `{.k}` and `{.k #H}` are
 * therefore two different documents. carve-rs ruled it in carve-rs#1354 and
 * carve-rs#1355, carve-js ported it, and carve-php had no carve-out at all
 * (carve-php#1699).
 *
 * ## Which element carries the id
 *
 * A TOP-LEVEL heading is wrapped: `# H` renders
 * `<section id="H"><h1>H</h1></section>` and the `<h1>` carries no id. That id
 * belongs to the SECTION, which processSection() already answers for. A heading
 * INSIDE a container is not sectioned, so the id sits on the `<h1>` itself.
 * This carve-out is that second placement, and it is the only one where the id
 * is a heading attribute.
 *
 * ## Both halves, and why neither alone is enough
 *
 * POSITION - the id sits after every authored attribute, with only the
 * `data-source-line` render annotation allowed to follow it.
 *
 * VALUE - it equals the DEFAULT slug of the heading's own plain text, or that
 * slug with the `-N` dedup tail, which starts at 2 because the first occurrence
 * takes the bare base.
 *
 * Position alone eats an id an author wrote LAST (`{.k #Other}`); value
 * equality alone cannot tell `{.k}` from an id an author wrote FIRST whose
 * value happens to be the slug (`{#H .k}`). Both controls are below, and each
 * is the one the other half would fail.
 *
 * THE DEFAULT SLUG ONLY, which is the limit the importer already accepts for
 * every other derived attribute: it cannot know which heading-id options the
 * render used, so a value no default equals is kept.
 */
class RoundtripReadsAGeneratedHeadingIdBackTest extends TestCase
{
    /**
     * @param string $html
     *
     * @return array<string, string>
     */
    protected static function written(string $html): array
    {
        $out = [];
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $out[$mode] = (new HtmlToCarve(importMode: $mode))->convert($html);
        }

        return $out;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function droppedProvider(): array
    {
        return [
            'the bare slug' => [
                '<ul><li>a<h1 class="k" id="H">H</h1></li></ul>',
                "- a\n  {.k #H}\n  # H\n",
                "- a\n  {.k}\n  # H\n",
            ],
            // `H-2` is what a second `# H` in one document is given, so it is
            // an id this engine would have produced itself.
            'the -N dedup form' => [
                '<ul><li>a<h1 class="k" id="H-2">H</h1></li></ul>',
                "- a\n  {.k #H-2}\n  # H\n",
                "- a\n  {.k}\n  # H\n",
            ],
            'a three-digit dedup tail' => [
                '<ul><li>a<h1 class="k" id="H-137">H</h1></li></ul>',
                "- a\n  {.k #H-137}\n  # H\n",
                "- a\n  {.k}\n  # H\n",
            ],
            // The id was the whole of the block, so dropping it must leave the
            // heading with no attributes rather than an empty `{}` line.
            'an id that was the only attribute' => [
                '<ul><li>a<h1 id="H">H</h1></li></ul>',
                "- a\n  {#H}\n  # H\n",
                "- a\n  # H\n",
            ],
            // `data-source-line` is emitted LAST on purpose, so an id in front
            // of it is still in the generated position. The annotation itself
            // is an ordinary kept attribute on the way back in.
            'an id in front of the render annotation' => [
                '<ul><li>a<h1 class="k" id="H" data-source-line="4">H</h1></li></ul>',
                "- a\n  {.k #H data-source-line=4}\n  # H\n",
                "- a\n  {.k data-source-line=4}\n  # H\n",
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $authored
     * @param string $generated
     */
    #[DataProvider('droppedProvider')]
    public function testRoundtripDropsItAndTheOtherTwoModesKeepIt(string $html, string $authored, string $generated): void
    {
        $written = self::written($html);

        // `safe` and `semantic` read HTML from anywhere, so the id is authored
        // input there. Losing it is the regression the other two engines had.
        $this->assertSame($authored, $written['safe']);
        $this->assertSame($authored, $written['semantic']);
        $this->assertSame($generated, $written['roundtrip']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keptProvider(): array
    {
        return [
            // THE VALUE HALF SAVES THIS ONE. `Other` sits exactly where a
            // generated id sits, so position alone would eat it.
            'an id written last whose value is not the slug' => [
                '<ul><li>a<h1 class="k" id="Other">H</h1></li></ul>',
                "- a\n  {.k #Other}\n  # H\n",
            ],
            // THE POSITION HALF SAVES THIS ONE, and it is the shape that makes
            // this a combination bug: `H` is exactly what `# H` generates, so
            // value equality alone would eat an id the author demonstrably
            // wrote - this engine would never have emitted it BEFORE the class.
            'an id written first whose value IS the slug' => [
                '<ul><li>a<h1 id="H" class="k">H</h1></li></ul>',
                "- a\n  {#H .k}\n  # H\n",
            ],
            // `-1` is never written, because the first occurrence takes the
            // bare base.
            'a -1 tail' => [
                '<ul><li>a<h1 class="k" id="H-1">H</h1></li></ul>',
                "- a\n  {.k #H-1}\n  # H\n",
            ],
            'a leading-zero tail' => [
                '<ul><li>a<h1 class="k" id="H-02">H</h1></li></ul>',
                "- a\n  {.k #H-02}\n  # H\n",
            ],
            'a non-digit tail' => [
                '<ul><li>a<h1 class="k" id="H-x">H</h1></li></ul>',
                "- a\n  {.k #H-x}\n  # H\n",
            ],
            'an empty tail' => [
                '<ul><li>a<h1 class="k" id="H-">H</h1></li></ul>',
                "- a\n  {.k #H-}\n  # H\n",
            ],
            'a digits-then-letter tail' => [
                '<ul><li>a<h1 class="k" id="H-2x">H</h1></li></ul>',
                "- a\n  {.k #H-2x}\n  # H\n",
            ],
            // The stamp says the id was authored, and no measurement beats a
            // statement.
            'an id the render stamped as explicit' => [
                '<ul><li>a<h1 class="k" id="H" data-djot-explicit-id="1">H</h1></li></ul>',
                "- a\n  {.k #H}\n  # H\n",
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('keptProvider')]
    public function testItIsKeptInEveryModeIncludingRoundtrip(string $html, string $expected): void
    {
        foreach (self::written($html) as $mode => $value) {
            $this->assertSame($expected, $value, 'mode ' . $mode);
        }
    }

    /**
     * A top-level heading's id is on the `<section>`, which processSection()
     * answers for. Nothing here changes it - the carve-out is on the `<h1>`
     * arm, which this shape does not reach with an id at all.
     */
    public function testTheSectionWrappedPlacementIsUntouched(): void
    {
        foreach (self::written('<section id="H"><h1>H</h1></section>') as $mode => $value) {
            $this->assertSame("# H\n", $value, 'mode ' . $mode);
        }
    }

    /**
     * THE AMBIGUITY IS IRREDUCIBLE, AND THE RENDER IS WHAT SURVIVES IT.
     *
     * `{.k #H}` and `{.k}` above `# H` render the SAME BYTES, because a
     * generated id goes exactly where that authored one was written. So no
     * importer can tell the two apart, and reading the id as generated cannot
     * change what the document renders - only which of two equivalent spellings
     * is written back.
     *
     * @return array<string, array{0: string}>
     */
    public static function fixedPointProvider(): array
    {
        return [
            'a generated id' => ["- a\n  {.k}\n  # H\n"],
            'an authored id written last whose value IS the slug' => ["- a\n  {.k #H}\n  # H\n"],
            'an authored id written first' => ["- a\n  {#H .k}\n  # H\n"],
            'an authored id that is not the slug' => ["- a\n  {.k #Other}\n  # H\n"],
            'an explicitly empty id' => ["- a\n  {id=\"\"}\n  # H\n"],
            'a second heading taking the -2 dedup form' => ["# H\n\n- a\n  {.k}\n  # H\n"],
        ];
    }

    /**
     * @param string $source
     */
    #[DataProvider('fixedPointProvider')]
    public function testTheHtmlIsAFixedPointOfTheRoundtripImport(string $source): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert($source);
        $back = (new HtmlToCarve(importMode: 'roundtrip'))->convert($html);

        $this->assertSame($html, $converter->convert($back));
    }
}
