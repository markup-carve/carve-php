<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088: the writer places no backslash that the bare parse does not ask for (carve-php#5725 to #5729).
 */
final class WriterNeedlessEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCanonical(): array
    {
        return [
            // #5725: the case-five `]` escapes inside a note give no kind to the text behind it.
            'note with escaped closers' => ['x ^[a\]/\]\]/]]a]', 'x ^[a\]/\]\]/]]a]'],
            'note with braced strong' => ['^[\]\]{*\]\]*}]]', '^[\]\]*\]\]*]]'],
            'note with escaped brackets' => ['x ^[*\[\][a]\[*]]', 'x ^[*\[\]\[a\]\[*]]'],
            // #5726: hyphens glued to the marks of a delete, and the text of a literal attribute block.
            'delete holding a hyphen pair' => ['a {--d--}', 'a {--d--}'],
            'delete at the start' => ['{--d--} e', '{--d--} e'],
            'literal block with a hyphen run' => ['a {d--e}', 'a {d--e}'],
            'literal block with quoted value' => ['a {k="*x* --"}', 'a {k="*x* --"}'],
            'literal block with an id' => ['a {#i}', 'a {#i}'],
            'dash after a literal block' => ['a {.c} b--c', 'a {.c} b--c'],
            // #5727: an empty full reference keeps its label.
            'empty full reference' => ['a [][l]', 'a [][l]'],
            'empty full reference with definition' => ["[l]: /u\n\na [][l]", "[l]: /u\n\na [][l]"],
            // #5728: a non-breaking space is no host, and a line comment brings its own space.
            'comment behind a non-breaking space' => ['a [x]\  %% c', 'a [x]\  %% c'],
            'block behind a non-breaking space' => ['a [x]\ {.c}', 'a [x]\ {.c}'],
            // #5729: a backtick inside a node on the line keeps a backtick fence from opening.
            'fence before an attributed strong' => ['\- ``` *b x`*{#foo}', '\- ``` *b x`*{#foo}'],
            'fence before a link with a backtick' => ['\- ``` [a`](u)', '\- ``` [a`](u)'],
            'fence before a link holding code' => ['\- ``` [`c`](u)', '\- ``` [`c`](u)'],
            'fence before a span with a backtick' => ['\> ``` [x`]{.c}', '\> ``` [x`]{.c}'],
        ];
    }

    #[DataProvider('provideCanonical')]
    public function testFormatWritesNoNeedlessBackslash(string $source, string $expected): void
    {
        foreach ([[], ['normalize' => true]] as $options) {
            $result = Carve::formatWithDiagnostics($source, $options);
            self::assertSame($expected . "\n", $result->source);
            self::assertSame([], $result->diagnostics);
        }
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected . "\n", Carve::write($tree));
    }

    /**
     * Still needed: a hyphen run glued to a dash, a block glued to a host, and a fence a node does not stop.
     *
     * @return array<string, array{string, string}>
     */
    public static function provideKept(): array
    {
        return [
            'block behind a strong' => ['*b*\{.c}', '*b*\{.c}'],
            'fence before a plain strong' => ['\- \``` *b x*{#foo}', '\- \``` *b x*{#foo}'],
            'hyphens beside a dash' => ['x-\-\---', 'x-\-\---'],
        ];
    }

    #[DataProvider('provideKept')]
    public function testFormatKeepsTheBackslashTheParseAsksFor(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame($expected . "\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * #5729: the tree of the ticket, with no source behind it.
     */
    public function testAttributedSpanBehindAMarkerLineFromATree(): void
    {
        $tree = [

            'type' => 'document',
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => '- ``` '],
                        ['type' => 'strong', 'attrs' => ['id' => 'foo'], 'children' => [['type' => 'text', 'value' => 'b x`']]],
                    ],
                ],
            ],
        ];
        $result = Carve::writeWithDiagnostics($tree);
        self::assertSame("\\- ``` *b x`*{#foo}\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }
}
