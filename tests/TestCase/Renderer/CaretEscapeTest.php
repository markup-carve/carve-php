<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §2: a character is escaped IF AND ONLY IF omitting the escape would
 * change the re-parsed AST. A lone `^` opens nothing since sup became
 * braced-only, so the writer escaped every caret in text for a construct that
 * no longer exists (markup-carve/carve#581).
 *
 * What still needs the escape is where the caret ABUTS a shape that reads it:
 * the inline footnote `^[…]` and the braced superscript's own delimiters.
 */
class CaretEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function caretProvider(): array
    {
        return [
            'after a brace' => ['}^p', '}^p'],
            'between words' => ['x^y^z', 'x^y^z'],
            'spaced' => ['a ^ b', 'a ^ b'],
            'sup-shaped run' => ['a ^sup^ ,sub, stays literal', 'a ^sup^ ,sub, stays literal'],
            'before a bracket keeps it' => ['a\\^[x]', 'a\\^[x]'],
            // The two braced-superscript delimiters: bare, each half would let
            // the pair form around content it does not own.
            'after a brace opener keeps it' => ['a{^b', 'a{\\^b'],
            'before a brace closer keeps it' => ['a^}b', 'a\\^}b'],
        ];
    }

    #[DataProvider('caretProvider')]
    public function testTheWriterEscapesOnlyWhatTheReParserNeeds(string $source, string $expected): void
    {
        $this->assertSame($expected . "\n", CarveConverter::toCarve($source . "\n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'bare caret' => ['}^p'],
            'sup-shaped run' => ['a ^sup^ b'],
            'inline footnote text' => ['a\\^[x]'],
            'braced sup as text' => ['\\{^x^\\}'],
            'braced sup construct' => ['{^x^}'],
            'caption-shaped line' => ['\\^ x'],
            'brace opener then caret' => ['a{^b'],
            'caret then brace closer' => ['a^}b'],
            'real caption' => ["![i](i.png)\n^ A caption"],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testFormattingPreservesTheRenderedDocument(string $source): void
    {
        $converter = new CarveConverter();
        $formatted = CarveConverter::toCarve($source . "\n");

        $this->assertSame($converter->convert($source . "\n"), $converter->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted), 'fmt is idempotent');
    }
}
