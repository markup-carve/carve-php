<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A refused `+` changes no owner (CARVE-P9-067): the `%%%` span it skipped stays
 * in the host only when the next visible line does, and otherwise reads as it
 * would without the `+` (PART 0 FAMILIES `invisible`, carve-php#5177).
 */
class ACommentSpanAfterARefusedPlusStandsWithTheNextLineTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function documents(): array
    {
        return [
            'a document-level line after a blank (corpus 855-2)' => [
                "- +\n%%%\nc\n%%%\n\ntext\n", [
                    'document 0..19',
                    '  list 0..3',
                    '    list_item 0..3',
                    '  comment 4..13',
                    '  paragraph 15..19',
                    '    text 15..19',
                ],
            ],
            'the end of the document' => [
                "- +\n%%%\nc\n%%%\n", [
                    'document 0..13',
                    '  list 0..3',
                    '    list_item 0..3',
                    '  comment 4..13',
                ],
            ],
            'an own-line plus' => [
                "- a\n+\n%%%\nc\n%%%\n", [
                    'document 0..15',
                    '  list 0..3',
                    '    list_item 0..3',
                    '      paragraph 2..3',
                    '        text 2..3',
                    '  comment 6..15',
                ],
            ],
            'a nested item leaves the span to its parent item' => [
                "- a\n  - +\n  %%%\n  c\n  %%%\n", [
                    'document 0..25',
                    '  list 0..25',
                    '    list_item 0..25',
                    '      paragraph 2..3',
                    '        text 2..3',
                    '      list 6..9',
                    '        list_item 6..9',
                    '      comment 12..25',
                ],
            ],
            'a line at the parent item content column' => [
                "- - +\n  %%%\n  c\n  %%%\n\n  text\n", [
                    'document 0..29',
                    '  list 0..29',
                    '    list_item 0..29',
                    '      list 2..5',
                    '        list_item 2..5',
                    '      comment 8..21',
                    '      paragraph 25..29',
                    '        text 25..29',
                ],
            ],
            'a quoted item' => [
                "> - +\n> %%%\n> c\n> %%%\n", [
                    'document 0..21',
                    '  block_quote 0..21',
                    '    list 2..5',
                    '      list_item 2..5',
                    '    comment 8..21',
                ],
            ],
            'a comment line after the span follows it out' => [
                "- +\n%%%\nc\n%%%\n%% d\n\ntext\n", [
                    'document 0..24',
                    '  list 0..3',
                    '    list_item 0..3',
                    '  comment 4..13',
                    '  comment 14..18',
                    '  paragraph 20..24',
                    '    text 20..24',
                ],
            ],
            'a sibling item keeps the comments in source order' => [
                "- +\n%%%\nx\n%%%\n%% d\n- b\n", [
                    'document 0..22',
                    '  list 0..22',
                    '    list_item 0..18',
                    '      comment 4..13',
                    '      comment 14..18',
                    '    list_item 19..22',
                    '      paragraph 21..22',
                    '        text 21..22',
                ],
            ],
            'a line at the content column keeps the span in the item' => [
                "- +\n%%%\nc\n%%%\n\n  text\n", [
                    'document 0..21',
                    '  list 0..21',
                    '    list_item 0..21',
                    '      comment 4..13',
                    '      paragraph 17..21',
                    '        text 17..21',
                ],
            ],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('documents')]
    public function testTheSpanStandsWhereTheNextLineDoes(string $source, array $expected): void
    {
        $this->assertSame($expected, self::outline(CarveConverter::carve()->parse($source)));
    }

    public function testADivKeepsAContentColumnLineInTheItem(): void
    {
        $html = (new CarveConverter())->convert("::: note\n- +\n%%%\nc\n%%%\n  more\n:::\n");
        $this->assertStringContainsString('<li>more</li>', $html);
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
