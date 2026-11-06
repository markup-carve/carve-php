<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SameLineNestedTaskMarkerSpanTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function markers(): array
    {
        return [
            'task and definition' => ["- [x]   - :: term\n           : desc\n           :: next\n           : body\n", 8],
            'task and plain item' => ["- [x]   - child\n", 8],
            'task with wider gap' => ["- [ ]      - child\n", 11],
            'plain nested marker' => ["* * child\n", 2],
        ];
    }

    #[DataProvider('markers')]
    public function testNestedListAndItemBeginAtTheirOwnMarker(string $source, int $offset): void
    {
        $document = CarveConverter::plainText()->parse($source);
        $outer = $document->getChildren()[0];
        self::assertInstanceOf(ListBlock::class, $outer);
        $outerItem = $outer->getChildren()[0];
        self::assertInstanceOf(ListItem::class, $outerItem);
        $nested = $outerItem->getChildren()[0];
        self::assertInstanceOf(ListBlock::class, $nested);
        $item = $nested->getChildren()[0];
        self::assertInstanceOf(ListItem::class, $item);

        foreach ([$nested, $item] as $node) {
            $position = $node->getPos();
            self::assertNotNull($position);
            self::assertSame($offset, $position->startOffset);
            self::assertSame($offset + 1, $position->startColumn);
        }
    }
}
