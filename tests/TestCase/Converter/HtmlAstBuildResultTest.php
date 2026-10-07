<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlAstBuildResult;
use MarkupCarve\Carve\Converter\HtmlImportSession;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class HtmlAstBuildResultTest extends TestCase
{
    use ScalingGuardTrait;

    public function testTextRunsKeepBoundariesAndSingleNodeMetadata(): void
    {
        $nodes = [
            ['type' => 'text', 'value' => 'a', 'attrs' => ['id' => 'joined']],
            ['type' => 'escaped_text', 'value' => ':b:'],
            ['type' => 'text', 'value' => 'c'],
            [

                'type' => 'emphasis',
                'children' => [
                    ['type' => 'text', 'value' => 'd'],
                    ['type' => 'escaped_text', 'value' => 'e'],
                ],
            ],
            ['type' => 'text', 'value' => 'f', 'attrs' => ['id' => 'single']],
            ['type' => 'line_break'],
            ['type' => 'escaped_text', 'value' => 'g'],
        ];
        $tree = $this->export($nodes);
        self::assertSame([
            ['type' => 'text', 'value' => 'a:b:c'],
            ['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => 'de']]],
            ['type' => 'text', 'value' => 'f', 'attrs' => ['id' => 'single']],
            ['type' => 'line_break'],
            ['type' => 'text', 'value' => 'g'],
        ], $tree['children'][0]['children']);
    }

    public function testEmptyTextRunsStillProduceAJoinedNode(): void
    {
        self::assertSame([['type' => 'text', 'value' => '']], $this->export([
            ['type' => 'text', 'value' => ''],
            ['type' => 'escaped_text', 'value' => ''],
        ])['children'][0]['children']);
        self::assertSame([], $this->export([])['children'][0]['children']);
    }

    public function testMalformedValuesKeepTheExistingFallbacks(): void
    {
        $nodes = [
            ['type' => 'text', 'value' => 42, 'attrs' => ['id' => 'single']],
            ['type' => 'line_break'],
            ['type' => 'text'],
            ['type' => 'text', 'value' => null],
            ['type' => 'escaped_text', 'value' => ['invalid']],
            ['type' => 'text', 'value' => 'tail'],
        ];
        self::assertSame([
            ['type' => 'text', 'value' => 42, 'attrs' => ['id' => 'single']],
            ['type' => 'line_break'],
            ['type' => 'text', 'value' => 'tail'],
        ], $this->export($nodes)['children'][0]['children']);
        self::assertSame([['type' => 'text']], $this->export([['type' => 'text']])['children'][0]['children']);
    }

    #[Group('scaling')]
    public function testLongAdjacentTextRunsScaleLinearly(): void
    {
        $piece = str_repeat('x', 512);
        $this->assertConversionScalesLinearly(
            function (string $input) use ($piece): void {
                $this->export(array_fill(0, intdiv(strlen($input), 512), ['type' => 'text', 'value' => $piece]));
            },
            str_repeat($piece, 2048),
            str_repeat($piece, 8192),
            'adjacent public AST text',
            2048,
            8192,
        );
    }

    /**
     * @param list<array{type: string, ...<string, mixed>}> $nodes
     *
     * @return array<string, mixed>
     */
    private function export(array $nodes): array
    {
        return (new HtmlAstBuildResult([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => $nodes]],
        ], new HtmlImportSession(), false))->publicTree();
    }
}
