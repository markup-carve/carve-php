<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-002, C2-W-088: a backslash stands where the bare form would read back as another tree, and nowhere else
 * (carve-php#5735, #5757, #5764, #5772, #5773, #5786, #5797, #5841).
 */
final class WriterEscapePlacementTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCanonical(): array
    {
        return [
            // #5773: C2-B-015, only a bullet or the value 1 opens a list below a paragraph line.
            'later item line starting 2.' => ["- a\n  2\\. li", "- a\n  2. li"],
            'later item line starting 10.' => ["- a\n  10\\. li", "- a\n  10. li"],
            'later item line with a roman 2' => ["I) a\n II) b", "I) a\n   II) b"],
            'later item line with a letter b' => ["- a\n  b\\) li", "- a\n  b) li"],
            'later quoted item line starting 2.' => ["> - a\n>   2\\. li", "> - a\n>   2. li"],
            'later footnote line starting 2.' => ["[^n]: a\n  2\\. li", "[^n]: a\n  2. li"],
            'later item line starting 1. keeps it' => ["- a\n  1\\. li", "- a\n  1\\. li"],
            'later item line with a roman 1 keeps it' => ["- a\n  i\\) li", "- a\n  i\\) li"],
            'later item line with a bullet keeps it' => ["- a\n  \\- li", "- a\n  \\- li"],
            // #5772: C2-B-166, C2-B-167, a line that ends in a hard break is no row.
            'pipe line ending in a hard break' => ['\\|a\\', '|a\\'],
            'pipe pair ending in a hard break' => ["\\|a|\\\nb", "|a|\\\nb"],
            'quoted pipe line ending in a hard break' => ['> \\|a\\', '> |a\\'],
            'pipe line with a row below keeps it' => ["\\|a|\n\\|b|\\\nc", "\\|a|\n\\|b|\\\nc"],
            // #5786: C2-W-069, a mark between two letters inside a braced span pairs with nothing (C2-I-053).
            'slash between letters in a braced strong' => ['/*x*{*a/b*})/', '/*x*{*a/b*})/'],
            'star between letters in a braced strong' => ['> *\\{*{*a*b*}{*a *}q', '> *\\{*{*a*b*}{*a *}q'],
            'star between letters behind an escaped strong' => ['*\\{*{*a*b*}{*a *}q', '*\\{*{*a*b*}{*a *}q'],
            'slash between letters in a bare strong keeps the brace' => ['{/*a/b*{*a/b*}c/}', '{/*a/b*{*a/b*}c/}'],
            'slash between letters in the text keeps the brace' => ['{/usr/local/}', '{/usr/local/}'],
            // #5841: C2-I-047, C2-I-048, the flanking of a mark reads the code point beside it, not a byte of it.
            'escaped star behind U+FFFD' => ["\u{FFFD}\\*©*[*", "\u{FFFD}\\*©*[*"],
            'escaped star behind a CJK letter' => ['日\\*本*[*', '日*本*[*'],
            'escaped star behind an accented letter' => ['é\\*x*[*', 'é*x*[*'],
            'star behind a non-breaking space' => ["a\\*\u{A0}*x*", "a*\u{A0}*x*"],
            // #5797: C2-I-089, behind an open text `^[` and inside a note a caret pair is an ordinary bracket.
            'caret pair behind an open text note' => ['^[a [^[b]](/u) z', '^[a [^[b]](/u) z'],
            'caret pair behind a text note and a link' => ['^[a [b]  [^[b] c](/u)', '^[a [b]  [^[b] c](/u)'],
            'caret pair behind a text note and code' => ['^[a  `s` [^[b] c]{.x}', '^[a  `s` [^[b] c]{.x}'],
            'caret pair behind a closed and an open note' => ['^[r] ^[a [b]  [^[b] c]{.x}', '^[r] ^[a [b]  [^[b] c]{.x}'],
            'caret pair behind a text note and strong' => ['^[a *s* [^[b]](/u)', '^[a *s* [^[b]](/u)'],
            'caret pair in link text inside a note' => ['^[a [^[b]](/u)]', '^[a [^[b]](/u)]'],
            'note pair in link text inside a note' => ['^[a [x ^[b]](/u)]', '^[a [x ^[b]](/u)]'],
            'reference pair in link text inside a note' => ['^[a [a [^b]](/u)]', '^[a [a [^b]](/u)]'],
            'caret pair behind an escaped bracket keeps the caret' => ['^[a \\[ [^[b]](/u)', '^[a [ [\\^[b]](/u)'],
            // #5764: C2-I-060, a bare brace before `*` with no closer takes the mark, which its backslash would free.
            'brace before a star in a highlight' => ['{=*}a}{\\*\\*{a\\*=}', '{=\\*}a}{*\\*{a*=}'],
            'brace before hyphens in a delete' => ['{-{--}-}', '{-{--}-}'],
            'brace before a hyphen run in a delete' => ['{-{--}--}', '{-{--}--}'],
            'attribute-shaped text behind a fence' => ['\\- ]```{#i}*x{k="`"}=}*{k="`"}', '\\- ]```{#i}*x{k="`"}=}*{k=`}'],
            // #5735: a run of two slashes is text (C2-I-049), so no escape splits it.
            'slash run before an emphasis holding code' => ['{//}/`x`/', '{//}/`x`/'],
            'slash run before an emphasis holding a link' => ['{//}/[x](u)/', '{//}/[x](u)/'],
        ];
    }

    #[DataProvider('provideCanonical')]
    public function testFormatPlacesOnlyTheNeededBackslash(string $source, string $expected): void
    {
        foreach ([[], ['normalize' => true]] as $options) {
            $result = Carve::formatWithDiagnostics($source, $options);
            self::assertSame($expected . "\n", $result->source);
            self::assertSame([], $result->diagnostics);
        }
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected . "\n", Carve::write($tree));
        self::assertSame(Carve::toHtml($source), Carve::toHtml($expected));
    }
}
