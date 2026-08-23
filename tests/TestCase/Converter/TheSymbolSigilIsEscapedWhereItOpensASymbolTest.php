<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `a :rocket: b` written back bare stops being the text the source held.
 *
 * This is PART 11 §2 applied to the source an importer writes, not a new rule
 * and not import policy: a character is escaped IF AND ONLY IF omitting the
 * escape would change the re-parsed AST, and `parse("a :rocket: b")` yields a
 * `symbol` node unconditionally. `:` is already in §5's candidate set, and the
 * tag sigil beside it was already hardened - the build wrote `a \#t b` for
 * `<p>a #t b</p>` - so this was one production spelled twice with one half
 * missing (markup-carve/carve#1601, `docs/html-import.md`).
 *
 * ONLY THE OPENING COLON opens anything. The closing one is preceded by a name
 * character, so `a \:rocket: b` is the whole escape and the second colon stays
 * bare - which is §2's per-OCCURRENCE test, not a knob turned once per line.
 *
 * The negative cases are the reason the rule mirrors `parseSymbol()` rather
 * than approximating it. A colon that closes no shortcode opens no symbol, and
 * a URL's colon has a letter in front of it; escaping either would put a
 * backslash in front of a character the author typed as itself, which §2 calls
 * a defect rather than a safe default.
 */
class TheSymbolSigilIsEscapedWhereItOpensASymbolTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sigilCases(): array
    {
        return [
            'a symbol shortcode' => ['a :rocket: b', 'a \:rocket: b'],
            'at the start of a line' => [':rocket: b', '\:rocket: b'],
            'a reaction shortcode' => ['a :+1: b', 'a \:+1: b'],
            'a colon that closes no shortcode' => ['a : b : c', 'a : b : c'],
            'an http url' => ['see http://example.com/a/b now', 'see http://example.com/a/b now'],
            'a non-http scheme' => ['see ftp://example.com/a/ now', 'see ftp://example.com/a/ now'],
            'a colon inside a word' => ['a x:y: b', 'a x:y: b'],
            'a time of day' => ['at 10:30 today', 'at 10:30 today'],
        ];
    }

    #[DataProvider('sigilCases')]
    public function testTheSigilIsEscapedOnlyWhereASymbolOpens(string $text, string $expected): void
    {
        $carve = (new HtmlToCarve())->convert('<p>' . $text . '</p>');

        $this->assertSame($expected, trim($carve));
    }

    /**
     * The escape is what keeps the text the text.
     *
     * Asserted through a converter with a symbol map configured, because that
     * is the configuration under which the bare form stops saying what the HTML
     * said - and the one a document is imported for.
     */
    public function testTheTextSurvivesAConfiguredSymbolMap(): void
    {
        $converter = new CarveConverter(symbols: ['rocket' => '<img alt="rocket">']);

        $this->assertStringContainsString('<img alt="rocket">', $converter->convert("a :rocket: b\n"));

        $carve = (new HtmlToCarve())->convert('<p>a :rocket: b</p>');
        $this->assertSame("<p>a :rocket: b</p>\n", $converter->convert($carve));
    }
}
