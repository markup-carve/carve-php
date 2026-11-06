<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ATableTextStartsAfterItsCellAttributesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cells(): array
    {
        return [
            'indented row' => [' |={k="a"} a |', 'a'],
            'colspan and sibling cells' => ['|={colspan=3} a | < | b |', 'a'],
            'unmarked attributed cell' => ['|{title="xy"} xy |', 'xy'],
            'quoted row' => ['> |={title="word"} word |', 'word'],
        ];
    }

    #[DataProvider('cells')]
    public function testTextSpanSelectsTheCellContent(string $source, string $text): void
    {
        $ast = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source . "\n"));
        $table = $ast['children'][0];
        if ($table['type'] === 'block_quote') {
            $table = $table['children'][0];
        }
        $node = $table['rows'][0]['cells'][0]['children'][0];
        $start = strrpos($source, $text);

        self::assertSame('text', $node['type']);
        self::assertSame($text, $node['value']);
        self::assertSame($start, $node['pos']['startOffset']);
        self::assertSame($start + strlen($text), $node['pos']['endOffset']);
    }
}
