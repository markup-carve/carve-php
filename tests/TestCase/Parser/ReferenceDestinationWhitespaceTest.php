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

    /**
     * A no-break space is excluded: this engine renders one in an href as the
     * `&nbsp;` entity, so the assertion would be about HTML escaping rather
     * than about trimming. It is covered by the both-ends case above.
     *
     * @return array<string, array{string}>
     */
    public static function interiorProvider(): array
    {
        $cases = self::unicodeWhitespaceProvider();
        unset($cases['no-break space']);

        return $cases;
    }

    #[DataProvider('interiorProvider')]
    public function testTheInteriorIsPreserved(string $space): void
    {
        // Only the ends are noise. A definition runs to end of line, so
        // whitespace inside the destination is unambiguous and part of it -
        // dropping it would silently rewrite the URL.
        $this->assertStringContainsString(
            'href="https://e.com' . $space . '/path"',
            (new CarveConverter())->convert("[x][r]\n\n[r]: https://e.com{$space}/path\n"),
        );
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

    public function testTheAnsiTargetShowsNoInvisibleCharacter(): void
    {
        // The case that made this visible: the destination is denied and blanked
        // in HTML, so only the ANSI target - which prints it - showed the engines
        // disagreeing.
        $converter = CarveConverter::ansi();
        /** @var \MarkupCarve\Carve\Renderer\AnsiRenderer $renderer */
        $renderer = $converter->getRenderer();
        $renderer->setUseColors(false);
        $out = $converter->convert("[click][a]\n\n[a]: \u{202F}javascript:alert(1)\n");

        $this->assertStringContainsString('javascript:alert(1)', $out);
        $this->assertStringNotContainsString("\u{202F}", $out);
    }
}
