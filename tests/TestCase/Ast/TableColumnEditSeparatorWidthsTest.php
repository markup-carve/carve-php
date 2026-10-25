<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableColumnEditSeparatorWidthsTest extends TestCase
{
    public function testBridgeWritesSchemaMapSeparatorMetadata(): void
    {
        $bridge = (new ProseMirrorRenderer())->render((new BlockParser())->parse("| a | b |\n|:-----|---:|\n| x | y |\n"));
        $table = $bridge['content'][0];

        self::assertSame([5, 3], $table['attrs']['carveSeparatorWidths']);
        self::assertSame(2, $table['attrs']['carveSeparatorColumnCount']);
        self::assertSame([5], $table['content'][0]['content'][0]['attrs']['carveSeparatorWidths']);
        self::assertSame([3], $table['content'][0]['content'][1]['attrs']['carveSeparatorWidths']);

        $map = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/resources/prosemirror-schema-map.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('carveSeparatorColumnCount', $map['types']['table']['attrs']);
        self::assertArrayHasKey('carveSeparatorWidths', $map['types']['table_cell']['attrs']);
    }

    /**
     * @return array<string, array{string, list<int>}>
     */
    public static function edits(): array
    {
        return [
            'delete first' => ['delete-first', [3, 4]],
            'delete middle' => ['delete-middle', [5, 4]],
            'insert middle' => ['insert-middle', [5, 3, 3, 4]],
            'move last first' => ['move-last-first', [4, 5, 3]],
        ];
    }

    #[DataProvider('edits')]
    public function testWidthsFollowEditedColumns(string $edit, array $expected): void
    {
        $source = "| a | b | c |\n|:-----|---:|:----:|\n| x | y | z |\n";
        $bridge = (new ProseMirrorRenderer())->render((new BlockParser())->parse($source));
        $table = &$bridge['content'][0];
        foreach ($table['content'] as &$row) {
            if ($edit === 'delete-first' || $edit === 'delete-middle') {
                array_splice($row['content'], $edit === 'delete-first' ? 0 : 1, 1);
            } elseif ($edit === 'insert-middle') {
                $new = ['type' => $row['content'][0]['type'], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'new']]]]];
                array_splice($row['content'], 1, 0, [$new]);
            } else {
                array_unshift($row['content'], array_pop($row['content']));
            }
        }
        unset($row, $table);

        $document = (new ProseMirrorToCarve())->convert($bridge);
        self::assertSame($expected, $document->getChildren()[0]->getSeparatorWidths());
        $written = (new CarveRenderer())->render($document);
        self::assertCount(count($expected), (new BlockParser())->parse($written)->getChildren()[0]->getChildren()[0]->getChildren());
    }

    public function testLegacyTableVectorCannotCreateAnExtraDelimiterCell(): void
    {
        $bridge = (new ProseMirrorRenderer())->render((new BlockParser())->parse("| a | b |\n|:-----|---:|\n| x | y |\n"));
        foreach ($bridge['content'][0]['content'] as &$row) {
            array_splice($row['content'], 1, 1);
            unset($row['content'][0]['attrs']['carveSeparatorWidths']);
        }
        unset($row);
        $document = (new ProseMirrorToCarve())->convert($bridge);
        self::assertSame([5], $document->getChildren()[0]->getSeparatorWidths());
        self::assertSame(1, substr_count(explode("\n", (new CarveRenderer())->render($document))[1], '|') - 1);
    }

    public function testAnUneditedRaggedTableKeepsItsShortSeparatorRow(): void
    {
        $source = "| |x |\n|---|\n| y |\n";
        $bridge = (new ProseMirrorRenderer())->render((new BlockParser())->parse($source));
        $document = (new ProseMirrorToCarve())->convert($bridge);
        self::assertSame([3], $document->getChildren()[0]->getSeparatorWidths());
        self::assertSame(
            (new CarveRenderer())->render((new BlockParser())->parse($source)),
            (new CarveRenderer())->render($document),
        );
    }

    public function testDeletingBesideAColspanRemovesTheOldDelimiterCell(): void
    {
        $source = "| x | Wide|<| y|\n|:--:|--:|:--|:--:|\n|p|a|b|c|\n";
        $bridge = (new ProseMirrorRenderer())->render((new BlockParser())->parse($source));
        foreach ($bridge['content'][0]['content'] as &$row) {
            array_splice($row['content'], 0, 1);
        }
        unset($row);
        $document = (new ProseMirrorToCarve())->convert($bridge);
        self::assertCount(3, $document->getChildren()[0]->getSeparatorWidths());
        self::assertStringContainsString("|--:|--|:--:|\n", (new CarveRenderer())->render($document));
    }
}
