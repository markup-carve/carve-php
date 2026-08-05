<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A link reference definition's destination is trimmed of UNICODE whitespace,
 * not just the ASCII that `trim()` knows.
 *
 * `[a]: <U+202F>javascript:alert(1)` kept the narrow no-break space in the
 * destination. HTML hid it - the scheme probe strips Unicode whitespace to see
 * `javascript:` and blanks the href either way - but the ANSI target prints the
 * destination to a terminal, and an invisible character there is the spoofing
 * shape the probe exists to catch (carve#352, carve#404).
 *
 * Zero-width characters are deliberately NOT whitespace and stay, matching
 * carve-rs, whose `str::trim` uses the Unicode White_Space property.
 */
class ReferenceDestinationWhitespaceTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unicodeWhitespaceProvider(): array
    {
        return [
            'no-break space' => ["\u{00A0}"],
            'thin space' => ["\u{2009}"],
            'narrow no-break space' => ["\u{202F}"],
            'ideographic space' => ["\u{3000}"],
        ];
    }

    #[DataProvider('unicodeWhitespaceProvider')]
    public function testItIsTrimmedFromBothEnds(string $space): void
    {
        $converter = new CarveConverter();

        $this->assertStringContainsString(
            'href="https://e.com"',
            $converter->convert("[x][r]\n\n[r]: {$space}https://e.com\n"),
        );
        $this->assertStringContainsString(
            'href="https://e.com"',
            $converter->convert("[x][r]\n\n[r]: https://e.com{$space}\n"),
        );
    }

    #[DataProvider('unicodeWhitespaceProvider')]
    public function testTheDestinationEndsAtInteriorWhitespace(string $space): void
    {
        // Not "trimmed at the ends, interior preserved" - that reads as the
        // friendlier rule and contradicts `link_destination`, which admits no
        // whitespace at all. The destination ENDS there, and what follows is
        // ignored unless it is a quoted title, exactly as a plain space behaves
        // (PART 9 link_destination, carve#404).
        $this->assertStringContainsString(
            'href="https://e.com"',
            (new CarveConverter())->convert("[x][r]\n\n[r]: https://e.com{$space}/path\n"),
        );
    }

    #[DataProvider('unicodeWhitespaceProvider')]
    public function testAnInlineDestinationCannotContainIt(string $space): void
    {
        // The inline form gets the same rule, so the link does not form at all.
        $out = (new CarveConverter())->convert("[x]({$space}https://e.com)\n");

        $this->assertStringNotContainsString('<a', $out);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function zeroWidthProvider(): array
    {
        return [
            'zero width space' => ["\u{200B}"],
            'byte order mark' => ["\u{FEFF}"],
        ];
    }

    #[DataProvider('zeroWidthProvider')]
    public function testAZeroWidthCharacterIsNotWhitespaceAndStays(string $char): void
    {
        $this->assertStringContainsString(
            'href="' . $char . 'https://e.com"',
            (new CarveConverter())->convert("[x][r]\n\n[r]: {$char}https://e.com\n"),
        );
    }

    #[DataProvider('unicodeWhitespaceProvider')]
    public function testAWhitespaceOnlyDestinationIsNotADefinition(string $space): void
    {
        // Trimming can empty the destination, and an empty destination is not a
        // definition - `[r]:` and `[r]:   ` already stay literal. The regex only
        // requires a non-space BYTE, which a Unicode space satisfies, so without
        // a re-check after trimming this registered a reference with an empty
        // href and rendered `<a href="">`.
        $out = (new CarveConverter())->convert("[x][r]\n\n[r]: {$space}\n");

        $this->assertStringNotContainsString('<a', $out);
        $this->assertStringContainsString('[x][r]', $out);
        // The line itself survives as prose rather than being swallowed.
        $this->assertStringContainsString('[r]:', $out);
    }

    public function testTheAnsiTargetShowsNoInvisibleCharacter(): void
    {
        // The case that made this visible: the destination is denied and blanked
        // in HTML, so only the ANSI target - which printed it - showed the engines
        // disagreeing.
        //
        // THIS EXPECTATION FLIPPED, deliberately. It used to assert the ANSI
        // target CONTAINS `javascript:alert(1)`, pinning the answer of the day so
        // that settling markup-carve/carve#765 could not be silent. It was settled
        // the other way: PART 9 §25 binds "every target that emits a resolvable
        // URL", a terminal autolinks a URL in its output and hands the scheme to
        // the OS handler on click, so the destination is now blanked here as it
        // already was in HTML and Markdown (carve-php#867).
        //
        // The subject of this case is unchanged and is what its name says: no
        // invisible character reaches the output. Blanking makes that strictly
        // stronger, and the assertion stays because the trim is what it guards -
        // a future change that stopped blanking must still not print U+202F.
        $converter = CarveConverter::ansi();
        /** @var \MarkupCarve\Carve\Renderer\AnsiRenderer $renderer */
        $renderer = $converter->getRenderer();
        $renderer->setUseColors(false);
        $out = $converter->convert("[click][a]\n\n[a]: \u{202F}javascript:alert(1)\n");

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString("\u{202F}", $out);
        // The reference still RESOLVED - it is the destination that is withheld,
        // not the link. An unresolved reference would print `[click][a]` instead.
        $this->assertStringContainsString('click ()', $out);
    }
}
