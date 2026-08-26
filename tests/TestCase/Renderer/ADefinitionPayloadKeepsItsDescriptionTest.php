<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition list in a body is written where its payload stays in the `dd`.
 *
 * PART 9 §24 C3 gives a recognized block opener past a footnote body's column 2
 * or a definition body's column 3 an authored local block base. A description's
 * payload sits at its separator's column, IN from the `::` line, so at the
 * body's minimum column the body's own rebase claims that payload and the
 * description keeps only its first paragraph. Written one column in, the `::`
 * line is the over-indented opener, its column becomes the entry's base, and
 * the run comes back with its relative columns intact.
 *
 * The writer narrowed to the minimum unconditionally, so `carve fmt` moved a
 * quote out of a `<dd>` and PART 11 §1's `to_html(fmt(x)) == to_html(x)` did
 * not hold (markup-carve/carve-php#1776).
 *
 * BOTH SIDES ARE PINNED. The raise is not a preference between two canonical
 * forms: where the un-raised spelling still says what the document says, PART
 * 11 §2 pins those bytes and they stay pinned. So the shapes that must STILL
 * narrow are asserted here beside the one that must not.
 */
class ADefinitionPayloadKeepsItsDescriptionTest extends TestCase
{
    /**
     * Whether the blockquote sits inside the description rather than beside it.
     */
    private static function quoteIsInsideTheDescription(string $html): bool
    {
        $open = strpos($html, '<dd>');
        $close = strpos($html, '</dd>');
        $quote = strpos($html, '<blockquote>');
        if ($open === false || $quote === false) {
            return false;
        }

        return $quote > $open && ($close === false || $quote < $close);
    }

    /**
     * A footnote body holding a definition list whose description holds a
     * quote, authored at BASE with SEPARATOR spaces after the `:`.
     */
    private static function footnoteBody(int $base, int $separator): string
    {
        $indent = str_repeat(' ', $base);
        $payload = str_repeat(' ', $base + 1 + $separator);

        return "[^n]: intro\n\n"
            . $indent . ":: term\n"
            . $indent . ':' . str_repeat(' ', $separator) . "definition\n\n"
            . $payload . "> quote\n\nsee[^n]\n";
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function keepsThePayloadProvider(): array
    {
        return [
            // THE EQUAL-COLUMN PAIR. Base 3 with a one-space separator puts the
            // quote at absolute column 5, and so does base 2 with a two-space
            // separator - and the two answer differently. The operative column
            // is the one the body's own container establishes, not the raw
            // indent, so a fix keyed on the indent fails one of the two.
            'base 3, separator 1, quote at column 5' => [self::footnoteBody(3, 1)],
            'base 3, separator 2, quote at column 6' => [self::footnoteBody(3, 2)],
            'base 4, separator 1, quote at column 6' => [self::footnoteBody(4, 1)],
            'base 4, separator 2, quote at column 7' => [self::footnoteBody(4, 2)],
        ];
    }

    /**
     * The other half of the equal-column pair, and the shapes that must still
     * narrow to the body minimum.
     *
     * @return array<string, array{0: string}>
     */
    public static function narrowsToTheMinimumProvider(): array
    {
        return [
            'base 2, separator 1, quote at column 4' => [self::footnoteBody(2, 1)],
            // The equal-column twin of `base 3, separator 1`: same absolute
            // column 5. The unified ownership rule now lets the exact entry
            // extent hold it without manufacturing an authored base.
            'base 2, separator 2, quote at column 5' => [self::footnoteBody(2, 2)],
        ];
    }

    /**
     * PART 11 §1: the written bytes render to what the authored bytes render to.
     */
    #[DataProvider('keepsThePayloadProvider')]
    public function testTheWrittenFormKeepsThePayloadInsideTheDescription(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);

        $this->assertTrue(
            self::quoteIsInsideTheDescription((new CarveConverter())->convert($source)),
            'the authored form does not put the quote inside the description',
        );
        $this->assertTrue(
            self::quoteIsInsideTheDescription((new CarveConverter())->convert($formatted)),
            'the written form moved the quote out of the description: ' . $formatted,
        );
        $this->assertSame(
            (new CarveConverter())->convert($source),
            (new CarveConverter())->convert($formatted),
            'formatting changed the rendered HTML',
        );
    }

    /**
     * Narrowing is right almost everywhere and stays.
     */
    #[DataProvider('narrowsToTheMinimumProvider')]
    public function testAnUnraisableBodyStillNarrowsToTheMinimum(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);

        $this->assertStringContainsString(
            "\n  :: term\n",
            $formatted,
            'the definition list left the body minimum: ' . $formatted,
        );
        $this->assertSame(
            (new CarveConverter())->convert($source),
            (new CarveConverter())->convert($formatted),
            'formatting changed the rendered HTML',
        );
    }

    /**
     * A description with nothing to lose keeps the un-raised canonical bytes.
     */
    public function testADescriptionWithNoIndentedPayloadIsStillNarrowed(): void
    {
        $source = "[^n]: intro\n\n   :: term\n   :  definition\n\nsee[^n]\n";

        $this->assertStringContainsString(
            "\n  :: term\n  : definition",
            CarveConverter::toCarve($source),
            'a description holding no indented block was raised anyway',
        );
    }

    /**
     * A quote and a list directly in a footnote body are not descriptions and
     * are narrowed like every other body block.
     */
    public function testANonDefinitionBlockInTheBodyIsStillNarrowed(): void
    {
        $quote = "[^n]: intro\n\n   > quote\n\nsee[^n]\n";
        $list = "[^n]: intro\n\n   - item\n\nsee[^n]\n";

        $this->assertStringContainsString("\n  > quote", CarveConverter::toCarve($quote));
        $this->assertStringContainsString("\n  - item", CarveConverter::toCarve($list));
    }
}
