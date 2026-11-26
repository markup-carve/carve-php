<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WriterRoundKindsRegressionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            '5869 bare brace before sigil' => ['/{:/', '/{:/'],
            '5869 brace in emphasis' => ['*{a~}*', '*{a~}*'],
            '5869 brace next to closer' => ['x {**}*}', 'x {**}*}'],
            '5869 nested open brace' => ['{+{++}', '{+{++}'],
            '5869 percent brace' => ['{% %%', '{% %%'],
            '5869 brace across line' => ["/a\n{a/", "/a\n{a/"],
            '5870 bare colon' => [':a[', ':a['],
            '5870 bang in brace' => ['{=!=}', '{=!=}'],
            '5870 plus in brace' => ['{-+-}', '{-+-}'],
            '5870 hash in brace' => ['{_#_}', '{_#_}'],
            '5870 slash sigil' => ['/</', '/</'],
            '5870 double dash' => ['<a--', '<a--'],
            '5870 smart run' => ["/*$\n/*", "/*$\n/*"],
            '5870 delimiter' => ["{=\n|=}", "{=\n|=}"],
            '5870 pipe beside quote' => ['\\> ||', '\\> \\||'],
            '5871 brackets after note' => ['^[[]](', '^[[]]('],
            '5871 brace after note' => ['^[[]{]', '^[[]{]'],
            '5871 bracket before note' => ['[[^]()', '[[^]()'],
            '5871 bang around note' => ['\\![^a]!', '\\![^a]!'],
            '5871 caret around note' => ['\\^[^[]^', '\\^[^[]^'],
            '5872 tag kind in a paragraph' => ['{#a}\\#a', '{\\#a}\\#a'],
            '5872 line kinds in a paragraph' => ["\\:::\n\\- a", "\\:::\n\\- a"],
            '5872 editorial comment then tag' => ['{\\#a#}', '\\{\\#a\\#}'],
        ];
    }

    #[DataProvider('cases')]
    public function testWriterPlacesTheRoundEscapes(string $source, string $expected): void
    {
        $written = Carve::formatWithDiagnostics($source);
        self::assertSame($expected . "\n", $written->source);
        self::assertSame([], $written->diagnostics);
        self::assertTrue(TreeEquality::equal(
            TreeEncoder::decodedForWriter(Carve::parse($source)),
            TreeEncoder::decodedForWriter(Carve::parse($written->source)),
        ));
    }
}
