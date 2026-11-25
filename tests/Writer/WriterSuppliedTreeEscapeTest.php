<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-W-091: `write()` of a tree gives the bytes `format()` gives for a source of that tree, without a
 * backslash the bare parse does not ask for (carve-php#6031).
 */
final class WriterSuppliedTreeEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideTrees(): array
    {
        $sup = str_repeat('{^', 201) . 'x' . str_repeat('^}', 201);
        $bracketSup = str_repeat('{^', 201) . '[x]' . str_repeat('^}', 201);
        $brackets = str_repeat('[', 199) . ':n[[' . str_repeat(']', 200);

        return [
            // A brace at a line start that opens nothing in the bare text.
            'attribute-looking lines' => ["{.a}\n{.b}\n\npara", "{.a}\n{.b}\n\npara"],
            'braced comment opener' => ["{% a\n%% b\n%}c", "{% a\n%% b\n%}c"],
            'brace inside a strong line' => ["*a\n{_x y*", "*a\n{_x y*"],
            'braced underline after text' => ['a {_k=v_} b and {_a_}', 'a {\_k=v_} b and {_a_}'],
            // A delimiter whose pair a comment breaks.
            'strong broken by a comment' => ['*a*{%%}a*', '*a*{%%}a*'],
            'code broken by a comment line' => ["`a\n%% b\nc`", "`a\n%% b\nc`"],
            'strong broken by a comment in an item' => ['1. *a*{%%}a*', '. *a*{%%}a*'],
            'pairs broken by comments on several lines' => [
                "b *a{%%}b*\n*a{%%}b*\n{% a\n%% b\n%}c",
                "b *a{%%}b*\n*a{%%}b*\n{% a\n%% b\n%}c",
            ],
            'braced comment on indented lines' => ["c *a{%%}b*\n  {% a\n  %% b\n  %}c", "c *a{%%}b*\n{% a\n%% b\n%}c"],
            // A block marker on a continuation line that the bare parse keeps as text.
            'description marker on a lazy line' => [
                "- a\n\n  :: t\n  : d\n : e\n",
                "- a\n\n  :: t\n  : d\n    : e",
            ],
            'list marker on a caption line' => ["> ![a](a.png)\n> ^ cap\n>   - c", "> ![a](a.png)\n> ^ cap\n>   - c"],
            // Text at the inline depth cap.
            'superscripts past the depth cap' => ['> ' . $sup, '> ' . $sup],
            'bracket in superscripts past the depth cap' => [$bracketSup, $bracketSup],
            'brackets past the depth cap' => [$brackets, $brackets],
        ];
    }

    #[DataProvider('provideTrees')]
    public function testWriteMatchesFormat(string $source, string $expected): void
    {
        self::assertSame($expected . "\n", Carve::write(Carve::parse($source)));
        self::assertSame(self::print($source), self::print($expected));
        // The sidecar keeps spellings such as the digits of an ordered marker, and adds no backslash either.
        $layout = Carve::parseWithLayout($source);
        self::assertSame(Carve::format($source), Carve::write($layout->tree, $layout->layout));
    }

    private static function print(string $source): string
    {
        return TreeEquality::fingerprint(TreeEncoder::decodedForWriter(Carve::parse($source)));
    }

    /**
     * C2-W-146: the caret escape of a caption-looking line stays where the bare form reads back.
     */
    public function testCaretLineKeepsItsEscape(): void
    {
        self::assertSame("{.x}\n\n\\^ cap\n", Carve::write(Carve::parse("{.x}\n^ cap\n")));
        self::assertSame("> a\n> \\^ cap\n", Carve::write(Carve::parse("> a\n> ^ cap\n")));
    }
}
