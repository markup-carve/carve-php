<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubItemFenceHostCloserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        /** @var array<array{id:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/sub_item_fence_host_closer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $row) {
            yield $row['id'] => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('rows')]
    public function testAFenceClosedAtTheItemColumnLeavesNoParagraphOpen(string $source, string $expected): void
    {
        $codec = new AstCodec();
        foreach ([new CarveConverter(), new CarveConverter(parser: new BlockParser(trackPositions: true))] as $converter) {
            $ast = $codec->decode($codec->encode($converter->parse($source)));
            foreach ([$converter->convert($source), $converter->render($ast)] as $html) {
                $this->assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
            }
        }
    }
}
