<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-002, C2-W-088: the written source reads back as the tree, with no backslash the bare parse does not ask for
 * (carve-php#5743 to #5751, #5753).
 */
final class WriterReadBackShapesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCanonical(): array
    {
        return [
            // #5753: a line comment ends a row-shaped line.
            'row line ending in a comment' => ["| a \\| %% c\nc", "\\| a | %% c\nc"],
            'row line ending in a comment, quoted' => ["> | a \\| %% c\n> c", "> \\| a | %% c\n> c"],
            'row line ending in a comment, item' => ["- | a \\| %% c\n  c", "- \\| a | %% c\n  c"],
            // #5743: a hyphen run of several dashes.
            'hyphen run of four' => ['a ---- b', 'a ---- b'],
            'hyphen run of five' => ['a ----- b', 'a ----- b'],
            // #5744: a note holding an empty full reference.
            'note holding an empty full reference' => ['x ![{/x\[^[^[][l]b]', 'x !\[{/x\[^[^[][l]b]'],
            // #5745: a plus before a hyphen run.
            'plus before a dash' => ['{+--}x', '{+--}x'],
            'plus between runs' => ['{++d-+--}', '{++d-+--}'],
            'plus-minus stays escaped' => ['a \+- b', 'a \+- b'],
            // #5746: a braced pair needs content.
            'empty highlight braces' => ['a {==} b', 'a {==} b'],
            'empty insert braces' => ['a {++} b', 'a {++} b'],
            // #5747: brackets inside strong.
            'brackets inside strong' => ['*[ ][]*', '*[ ][]*'],
            'empty brackets inside strong' => ['*[][]*', '*[][]*'],
            // #5748: a strong whose opener cannot close.
            'star before a bracketed strong' => ['*[*b*][^x]', '*[*b*][^x]'],
            'star before a parenthesized strong' => ['*(*b*) c', '*(*b*) c'],
            // #5749: a pair behind a full reference whose text is an escaped bracket.
            'pair behind a full reference' => ['[\[][l][m]', '[\[][l][m]'],
            'pair behind a collapsed reference' => ['[\[][][m]', '[\[][][m]'],
            // #5750: a backtick inside a literal attribute block.
            'fence before a literal block with a backtick' => ['\- ```x*b*{k="`"}`', '\- ```x*b*{k="`"}`'],
            'literal block in a strong with a backtick' => ['*{+!/[b{k="`"}`-]/', '*{+!/[b{k="`"}`-]/'],
            'escaped brace behind a code span' => ['`=} +}=}`\{k=`}', '`=} +}=}`\{k=`}'],
            // #5751: a leading delimiter of a content pairs with a later one.
            'leading slash of an emphasis' => ['{/\/y *x/*/}', '{/\/y *x/*/}'],
            'leading slash of a strong' => ['e {*\/{/\/*/}*}', 'e {*\/{/\/*/}*}'],
            // #5764: a brace whose backslash would change the read.
            'brace before a dash in a delete' => ['{-{--}-}', '{-{--}-}'],
            'brace before a dash and a hyphen' => ['{-{--}--}', '{-{--}--}'],
            'attribute blocks in a strong' => ['\- ]```{#i}*x{k="`"}=}*{k="`"}', '\- ]```{#i}*x{k="`"}=}*{k=`}'],
            // #5769: an attribute-shaped text behind a host, with atoms in the block.
            'block text behind a strong with a backtick' => ['*b*\{k="`"}\`', '*b*\{k="`"}\`'],
            'block text behind a strong into a code span' => ['*b*\{k="`"}x`', '*b*\{k="`"}x`'],
            'block text behind a link into a code span' => ['[a](u)\{k="`"}`', '[a](u)\{k="`"}`'],
            'block text into a code span' => ['$m$\{k="`"}` z', '$m$\{k="`"}` z'],
            // #5765: a line the bare parse opens behind an escaped opener line.
            'list item behind a heading line' => ["\\# a\n- c", "\\# a\n\\- c"],
            'list item behind a row line' => ["\\| a |\n- c", "\\| a |\n\\- c"],
            'ordered item behind a list line' => ["\\- a\n1. c", "\\- a\n1\\. c"],
            'heading behind a row line' => ["\\| a |\n# c", "\\| a |\n\\# c"],
            'text behind a heading line' => ["\\# a\nc", "\\# a\nc"],
            'lazy line behind a quote line' => ["\\> a\n- c", "\\> a\n- c"],
            // #5766: a brace inside a span that forms nothing.
            'empty pair in an insert' => ['{+{,,}}+}', '{+{,,}}+}'],
            'empty pair in a delete' => ['{-{^^}-}', '{-{^^}-}'],
            'literal block in a strong' => ['*{.c}(*', '*{.c}(*'],
            'opener without closer in an insert' => ['{++! {=+}', '{++! {=+}'],
            // #5771: a pipe behind a line comment ends a row line.
            'two lines ending in a comment and a pipe' => ["\\| %% c-|\n\\| %% c-|", "\\| %% c-|\n\\| %% c-|"],
            'later line ending in a comment and a pipe' => ["x\n\\| %% c-|", "x\n\\| %% c-|"],
            'quoted line ending in a comment and a pipe' => ["> \\| a %% c|\n> \\| %% |", "> \\| a %% c|\n> \\| %% |"],
            // carve#6462: a strong that reads on past its end takes its braced form, and the text behind it no escape.
            'braced strong before brackets' => ['{*{ *}[*[*]', '{*{ *}[*[*]'],
            'braced emphasis before brackets' => ['{/{ /}[/[/]', '{/{ /}[/[/]'],
            // carve#6461: the escape of a closer that would end its node early gives no kind.
            'closer repair in a superscript' => ['- \\{^{^^{^^}^^}^}', '- \\{^{^^\\{^\\^}^^}^}'],
            'closer repair beside a group' => ['{^{^^}{,^{^[,}^}{^]\\{}[^}^}', '{^\\{^\\^}{,^\\{^\\[,}^}{^]{}\\[^}^}'],
        ];
    }

    #[DataProvider('provideCanonical')]
    public function testWritesTheCanonicalForm(string $source, string $expected): void
    {
        foreach ([[], ['normalize' => true]] as $options) {
            $result = Carve::formatWithDiagnostics($source, $options);
            self::assertSame($expected . "\n", $result->source);
            self::assertSame([], $result->diagnostics);
        }
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        $written = Carve::writeWithDiagnostics($tree);
        self::assertSame($expected . "\n", $written->source);
        self::assertSame([], $written->diagnostics);
        self::assertTrue(TreeEquality::equal(
            TreeEncoder::decodedForWriter(Carve::parse($source)),
            TreeEncoder::decodedForWriter(Carve::parse($written->source)),
        ));
    }

    /**
     * #5771: a line comment with a line feed is written with a space there, and the row test reads it so.
     */
    public function testACommentWithALineFeedEndsNoRow(): void
    {
        $comment = ['type' => 'comment', 'form' => 'line', 'value' => "x\n|"];
        $paragraph = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => '| '], $comment]];
        $result = Carve::writeWithDiagnostics(['type' => 'document', 'astVersion' => '2.0', 'children' => [$paragraph]]);

        self::assertSame("\\|  %% x |\n", $result->source);
        self::assertSame(['field-unspellable'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
    }

    /**
     * #5751: the HTML import shapes of the ticket.
     *
     * @return array<string, array{string, string}>
     */
    public static function provideImported(): array
    {
        return [
            'emphasis starting with a slash' => ['<p><em>/y <strong>x/</strong></em></p>', '{/\/y *x/*/}'],
            'strong starting with a slash' => ['<p>e <strong>/<em>/*</em></strong></p>', 'e {*\/{/\/*/}*}'],
        ];
    }

    #[DataProvider('provideImported')]
    public function testWritesImportedTreesThatReadBack(string $html, string $expected): void
    {
        $tree = Carve::importHtml($html)->tree;
        $source = Carve::write($tree);

        self::assertSame($expected . "\n", $source);
        self::assertSame(trim(Carve::toHtml($source)), $html);
    }
}
