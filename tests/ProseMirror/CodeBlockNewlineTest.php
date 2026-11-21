<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeBlockNewlineTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function payloads(): iterable
    {
        yield 'no terminator' => ['x', "x\n"];
        yield 'one terminator' => ["x\n", "x\n"];
        yield 'two terminators' => ["x\n\n", "x\n\n"];
        yield 'empty block' => ['', "\n"];
    }

    #[DataProvider('payloads')]
    public function testProseMirrorTextKeepsExistingTerminator(string $text, string $expected): void
    {
        $content = $text === '' ? [] : [['type' => 'text', 'text' => $text]];
        $doc = ['type' => 'doc', 'content' => [['type' => 'codeBlock', 'content' => $content]]];
        $tree = (new ProseMirrorToCarve())->toTree($doc);

        $this->assertSame($expected, $tree['children'][0]['value']);
        $back = (new ProseMirrorToCarve())->toTree((new ProseMirrorRenderer())->render($tree));
        $this->assertSame($expected, $back['children'][0]['value']);
    }

    public function testParsedCodeBlocksRoundTripThroughProseMirror(): void
    {
        $bridge = new ProseMirrorToCarve();
        $renderer = new ProseMirrorRenderer();
        foreach (["```\nx\n```\n", "```\nx\n\n```\n", "```\n```\n"] as $source) {
            $tree = Carve::parse($source);
            $back = $bridge->toTree($renderer->render($tree));
            $this->assertTrue(TreeEquality::equal(
                json_decode(Carve::encodeTree($tree), true),
                json_decode((string)json_encode($back), true),
            ), $source);
        }
    }

    public function testTreeWithoutCodeTerminatorRoundTrips(): void
    {
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                ['type' => 'code_block', 'value' => 'x'],
            ],
        ];
        $back = (new ProseMirrorToCarve())->toTree((new ProseMirrorRenderer())->render($tree));
        $this->assertSame('x', $back['children'][0]['value']);
    }

    public function testGeneratedCodePayloadsKeepTheirTrailingNewlines(): void
    {
        $bridge = new ProseMirrorToCarve();
        $renderer = new ProseMirrorRenderer();
        for ($length = 1; $length <= 256; $length++) {
            for ($newlines = 0; $newlines <= 3; $newlines++) {
                $value = str_repeat(chr(97 + $length % 26), $length) . str_repeat("\n", $newlines);
                $tree = [

                    'type' => 'document',
                    'astVersion' => '2.0',
                    'children' => [
                        ['type' => 'code_block', 'value' => $value],
                    ],
                ];
                $back = $bridge->toTree($renderer->render($tree));
                $this->assertSame($value, $back['children'][0]['value'], "$length bytes, $newlines newlines");
            }
        }
    }
}
