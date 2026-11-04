<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P12-022 as amended for carve#5136: a node joined across a `%%` line S1
 * erased omits `pos`, the comment keeps its own span.
 */
class ANodeJoinedAcrossAnErasedCommentLineOmitsItsPositionTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function documents(): array
    {
        return [
            'paragraph (corpus 662)' => [
                "a\n%%\tx\nb\n", [
                    'document 0..8',
                    '  paragraph -',
                    '    text 0..1',
                    '    soft_break -',
                    '    text 7..8',
                    '  comment 2..6',
                ],
            ],
            'paragraph in an item (corpus 183)' => [
                "- a\n %% c\nb\n", [
                    'document 0..11',
                    '  list 0..11',
                    '    list_item 0..11',
                    '      paragraph -',
                    '        text 2..3',
                    '        soft_break -',
                    '        text 10..11',
                    '      comment 5..9',
                ],
            ],
            'footnote whose comment is hoisted' => [
                "[^a]: intro\n%% c\n more\n\nsee[^a]\n", [
                    'document 0..31',
                    '  comment 12..16',
                    '  paragraph 24..31',
                    '    text 24..27',
                    '    footnote_ref 27..31',
                    '  footnote -',
                    '    paragraph -',
                    '      text 6..11',
                    '      soft_break -',
                    '      text 18..22',
                ],
            ],
            'two erased lines in one paragraph' => [
                "a\n%% x\nb\n%% y\nc\n", [
                    'document 0..15',
                    '  paragraph -',
                    '    text 0..1',
                    '    soft_break -',
                    '    text 7..8',
                    '    soft_break -',
                    '    text 14..15',
                    '  comment 2..6',
                    '  comment 9..13',
                ],
            ],
            'a comment between blocks joins nothing' => [
                "a\n\n%% x\n\nb\n", [
                    'document 0..10',
                    '  paragraph 0..1',
                    '    text 0..1',
                    '  comment 3..7',
                    '  paragraph 9..10',
                    '    text 9..10',
                ],
            ],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('documents')]
    public function testTheJoinedNodesOmitPos(string $source, array $expected): void
    {
        $this->assertSame($expected, self::outline(CarveConverter::carve()->parse($source)));
    }

    /**
     * The canonical writer still puts the comment back between the paragraph's lines.
     */
    public function testTheWriterKeepsTheCommentBetweenTheJoinedLines(): void
    {
        $writer = CarveConverter::carve();
        foreach (["a\n%% x\nb\n", "a\n%% x\nb\n%% y\nc\n", "*a\n%% x\nb*\n"] as $source) {
            $this->assertSame($source, $writer->render($writer->parse($source)));
        }
        $this->assertSame("- a\n  %% c\n  b\n", $writer->render($writer->parse("- a\n %% c\nb\n")));
    }

    /**
     * @return list<string>
     */
    private static function outline(Node $node, int $depth = 0): array
    {
        $pos = $node->getPos();
        $lines = [str_repeat('  ', $depth) . $node->getType() . ' ' . ($pos === null ? '-' : $pos->startOffset . '..' . $pos->endOffset)];
        foreach ($node->getChildren() as $child) {
            array_push($lines, ...self::outline($child, $depth + 1));
        }

        return $lines;
    }
}
