<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use MarkupCarve\Carve\Converter\HtmlAstBuildResult;
use MarkupCarve\Carve\Converter\HtmlImportSession;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * @return array<string, array{string}>
     */
    public static function codecMethods(): array
    {
        return ['encode' => ['encode'], 'decode' => ['decode']];
    }

    #[DataProvider('codecMethods')]
    #[Group('scaling')]
    public function testCodecTextRunsScaleLinearly(string $method): void
    {
        $piece = str_repeat('x', 512);
        $codec = new AstCodec();
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($codec, $method, $piece): void {
                if ($method === 'decode') {
                    $codec->decode([
                        'type' => 'document',
                        'srcByteLength' => strlen($input),
                        'children' => [
                            [
                                'type' => 'paragraph',
                                'children' => array_fill(0, intdiv(strlen($input), 512), ['type' => 'text', 'value' => $piece]),
                            ],
                        ],
                    ]);
                } else {
                    $document = new Document();
                    $paragraph = new Paragraph();
                    $paragraph->setChildren(array_map(static fn (string $text): Text => new Text($text), str_split($input, 512)));
                    $document->appendChild($paragraph);
                    $codec->encode($document);
                }
            },
            str_repeat($piece, 2048),
            str_repeat($piece, 8192),
            'adjacent codec text ' . $method,
            2048,
            8192,
        );
    }

    public function testLongInternalSpacesDoNotPreventTrimmingTrailingPadding(): void
    {
        $spaces = str_repeat('<span> </span>', 4096);
        $tree = (new HtmlAstBuilder(sourceSafe: false))->build('<p>x' . $spaces . 'x </p>');
        self::assertSame('x' . str_repeat(' ', 4096) . 'x', $tree['children'][0]['children'][0]['value']);
    }

    #[Group('scaling')]
    public function testBuilderTextRunsScaleLinearly(): void
    {
        $fragment = '<span>' . str_repeat('x', 512) . '</span>';
        $builder = new HtmlAstBuilder(sourceSafe: false);
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($builder): void {
                $builder->build($input);
            },
            str_repeat($fragment, 2048),
            str_repeat($fragment, 8192),
            'adjacent HTML span text',
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
