<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 CARVE-P12-013, -014 and -019: a container starts at its opening
 * markup and ends at its last placed child; a refused `+` owns no source.
 */
class AContainerSpanIgnoresARefusedPlusTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function documents(): array
    {
        return [
            'nested lists end before a refused plus (carve-php#5164)' => [
                "- - - ```\n      x\n    +\ntail\n", [
                    'document 0..28',
                    '  list 0..17',
                    '    list_item 0..17',
                    '      list 2..17',
                    '        list_item 2..17',
                    '          list 4..17',
                    '            list_item 4..17',
                    '              code_block 6..17',
                    '  paragraph 24..28',
                    '    text 24..28',
                ],
            ],
            'an item emptied but for its plus still spans it' => [
                "- +\n[^m]: n\n", [
                    'document 0..11',
                    '  list 0..3',
                    '    list_item 0..3',
                    '  footnote 4..11',
                    '    paragraph 10..11',
                    '      text 10..11',
                ],
            ],
            'an empty figure group keeps its comment and span (carve-php#5168)' => [
                "::: figure\n%% hidden\n:::\n", [
                    'document 0..24',
                    '  figure_group 0..24',
                    '    comment 11..20',
                ],
            ],
            'an item starts at its marker when a plus takes its first block (carve-php#5167)' => [
                "- +\n> q\n  # g\n", [
                    'document 0..13',
                    '  list 0..13',
                    '    list_item 0..13',
                    '      block_quote 4..7',
                    '        paragraph 6..7',
                    '          text 6..7',
                    '      heading 10..13',
                    '        text 12..13',
                ],
            ],
            'a footnote after a losing plus note starts at its label (carve-php#5167)' => [
                "[^n]: +\n[^m]: d1\n[^m]: +\n\n  para\n\nsee[^n][^m]\n", [
                    'document 0..45',
                    '  paragraph 34..45',
                    '    text 34..37',
                    '    footnote_ref 37..41',
                    '    footnote_ref 41..45',
                    '  footnote 0..32',
                    '    paragraph 28..32',
                    '      text 28..32',
                    '  footnote 8..16',
                    '    paragraph 14..16',
                    '      text 14..16',
                ],
            ],
            'a refused-plus description starts at the item content column (carve#5976)' => [
                "1. a\n   :: t\n   : +\n> q\n", [
                    'document 0..23',
                    '  list 0..19',
                    '    list_item 0..19',
                    '      paragraph 3..4',
                    '        text 3..4',
                    '      definition_list 8..19',
                    '        definition_term 8..12',
                    '          text 11..12',
                    '        definition_description 16..19',
                    '  block_quote 20..23',
                    '    paragraph 22..23',
                    '      text 22..23',
                ],
            ],
            'a description after a plus-filled one starts at its marker (carve#5976)' => [
                "- x\n\n  :: t\n  : +\n  after\n  : b\n", [
                    'document 0..31',
                    '  list 0..31',
                    '    list_item 0..31',
                    '      paragraph 2..3',
                    '        text 2..3',
                    '      definition_list 7..31',
                    '        definition_term 7..11',
                    '          text 10..11',
                    '        definition_description 14..25',
                    '          paragraph 20..25',
                    '            text 20..25',
                    '        definition_description 28..31',
                    '          paragraph 30..31',
                    '            text 30..31',
                ],
            ],
            'an unclosed fence a refused plus ends owns only its opener (carve#5975)' => [
                "- ```\n+\n\n  x\n", [
                    'document 0..12',
                    '  list 0..12',
                    '    list_item 0..12',
                    '      code_block 2..5',
                    '      paragraph 11..12',
                    '        text 11..12',
                ],
            ],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('documents')]
    public function testTheSpansFollowTheOwnedSource(string $source, array $expected): void
    {
        $this->assertSame($expected, self::outline(CarveConverter::carve()->parse($source)));
    }

    public function testTheWriterKeepsTheCommentInsideTheFigureGroup(): void
    {
        $writer = CarveConverter::carve();
        $source = "::: figure\n%% hidden\n:::\n";
        $this->assertSame($source, $writer->render($writer->parse($source)));
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
