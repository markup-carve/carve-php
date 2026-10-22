<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unterminated nested fence above an indented `+` keeps one blank as payload;
 * the next blank separates the outer item's paragraph and is outside the span.
 */
class ADroppedFenceBlankLeavesTheSpanTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function rows(): array
    {
        $slug = '626-an-indented-plus-below-a-nested-fence-stays-content-at-every-depth-';

        return [
            '626-5' => [$slug . '5', 9],
            '626-6' => [$slug . '6', 19],
            '626-7' => [$slug . '7', 10],
        ];
    }

    #[DataProvider('rows')]
    public function testTheCodeBlockAndItsInnerListEndAfterThePayload(string $slug, int $expectedEnd): void
    {
        $source = file_get_contents(__DIR__ . '/../../spec/tests/corpus/' . $slug . '.crv');
        self::assertIsString($source);
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $root = (new AstCodec())->encode($converter->parse($source));

        $chain = [];
        $walk = static function (array $node, array $ancestors) use (&$walk, &$chain): void {
            if (($node['type'] ?? null) === 'code_block') {
                $chain = [...$ancestors, $node];
            }
            foreach (['children', 'items'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    $walk($child, [...$ancestors, $node]);
                }
            }
        };
        $walk($root, []);

        $code = array_pop($chain);
        self::assertIsArray($code);
        self::assertSame("\n", substr($code['content'], -1));
        self::assertSame($expectedEnd, $code['pos']['endOffset'], $slug);
        $item = array_pop($chain);
        $list = array_pop($chain);
        self::assertSame('list_item', $item['type']);
        self::assertSame('list', $list['type']);
        self::assertSame($expectedEnd, $item['pos']['endOffset'], $slug);
        self::assertSame($expectedEnd, $list['pos']['endOffset'], $slug);
    }
}
