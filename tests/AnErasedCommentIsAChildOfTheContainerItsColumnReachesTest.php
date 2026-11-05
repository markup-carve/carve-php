<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5178: an erased `%%` line is a child of the innermost container whose
 * content column it reaches, else the document; the lines after it do not move it.
 */
class AnErasedCommentIsAChildOfTheContainerItsColumnReachesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shapes(): array
    {
        return [
            'simple case' => ["- a\n\n  %% n\n- b\n", 'document/list/list_item'],
            'corpus 620-14' => ["- - a\n\n  %% just a note\n- b\n", 'document/list/list_item'],
            'corpus 504-18' => [":: a\n: b\n  :: c\n  %% note\n  more\n", 'document/definition_list/definition_description'],
            'corpus 937' => ["- :: term\n  : payload\n\n    %% c\n- sub\n", 'document/list/list_item/definition_list/definition_description'],
            'column 0 between items' => ["- a0\n%% n\n- b0\n", 'document'],
            'column 0 before a continuation' => ["- a0\n%% n\n  more\n", 'document'],
            'outer column before a nested sibling' => ["- a0\n  - a1\n  %% n\n  - b1\n", 'document/list/list_item'],
            'outer column before nested text' => ["- a0\n  - a1\n  %% n\n    more\n", 'document/list/list_item'],
            'after a term' => [":: t\n%% c\n: body\n", 'document/definition_list/definition_description'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheCommentsParentFollowsItsColumn(string $source, string $parent): void
    {
        $tree = (new AstCodec())->encode((new CarveConverter(parser: new BlockParser(false, false, false, true)))->parse($source));

        self::assertSame([$parent], self::commentParents($tree, 'document'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function writtenInPlace(): array
    {
        return [
            'column 0 between items' => ["- a0\n%% n\n- b0\n"],
            'column 0 before a continuation' => ["- a0\n%% n\n  more\n"],
            'outer column before a nested sibling' => ["- a0\n  - a1\n  %% n\n  - b1\n"],
            'outer column before nested text' => ["- a0\n  - a1\n  %% n\n    more\n"],
            'two comments in one paragraph keep their order' => ["- a\n%% x\n  %% y\n  more\n"],
            'two comments between items keep their order' => ["- a0\n  - a1\n%% x\n  %% y\n  - b1\n- b0\n"],
        ];
    }

    #[DataProvider('writtenInPlace')]
    public function testTheWriterKeepsTheCommentAtItsLine(string $source): void
    {
        self::assertSame($source, CarveConverter::carve(new BlockParser(false, false, false, true))->convert($source));
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     *
     * @return array<string>
     */
    private static function commentParents(array $node, string $path): array
    {
        $found = [];
        foreach (['children', 'items'] as $key) {
            /** @var array<array{type: string}&array<string, mixed>> $children */
            $children = $node[$key] ?? [];
            foreach ($children as $child) {
                if ($child['type'] === 'comment') {
                    $found[] = $path;
                } else {
                    $found = [...$found, ...self::commentParents($child, $path . '/' . $child['type'])];
                }
            }
        }

        return $found;
    }
}
