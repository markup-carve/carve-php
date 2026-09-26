<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

final class SectionAndBlockCellInterchangeTest extends TestCase
{
    public function testSmallCapsAndEquationFieldsUseConversionDiagnostics(): void
    {
        $wire = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'small_caps', 'children' => [['type' => 'text', 'value' => 'Nato']]],
                        ['type' => 'text', 'value' => ' '],
                        ['type' => 'math', 'content' => 'x', 'display' => true, 'label' => 'Eq.', 'number' => 2],
                    ],
                ],
            ],
        ];
        $document = (new AstCodec())->decode($wire);
        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection(2);
        $writer->render($document);
        $report = $writer->finishConversionDiagnosticCollection();

        self::assertSame(3, $report['totalDiagnostics']);
        self::assertTrue($report['truncated']);
        self::assertSame('structure-unspellable', $report['diagnostics'][0]['code']);
        self::assertSame('small_caps', $report['diagnostics'][0]['node']);
        self::assertSame('label', $report['diagnostics'][1]['field']);
    }

    public function testAnExplicitSectionRendersAndReportsItsSourceLoss(): void
    {
        $wire = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'section',
                    'level' => 2,
                    'attrs' => ['id' => 'unit'],
                    'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'Part']]]],
                ],
            ],
        ];
        $codec = new AstCodec();
        $document = $codec->decode($wire);

        self::assertEquals($wire, $codec->encode($document));
        self::assertSame("<section id=\"unit\">\n  <p>Part</p>\n</section>\n", (new HtmlRenderer())->render($document));
        foreach ([new MarkdownRenderer(), new PlainTextRenderer(), new AnsiRenderer()] as $renderer) {
            self::assertSame('Part', trim($renderer->render($document)));
        }

        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();
        self::assertSame('Part', trim($writer->render($document)));
        $report = $writer->finishConversionDiagnosticCollection();
        self::assertSame(1, $report['totalDiagnostics']);
        self::assertSame('structure-unspellable', $report['diagnostics'][0]['code']);
        self::assertSame('section', $report['diagnostics'][0]['node']);
        self::assertArrayNotHasKey('field', $report['diagnostics'][0]);
    }

    public function testABlockCellKeepsBlocksAndFlattensOnlyLineTargets(): void
    {
        $wire = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'table', 'rows' => [
                        [
                            'type' => 'table_row', 'cells' => [
                                [
                                    'type' => 'table_cell',
                                    'header' => false,
                                    'blocks' => [
                                        ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'one']]],
                                        ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'two']]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $codec = new AstCodec();
        $document = $codec->decode($wire);

        self::assertEquals($wire, $codec->encode($document));
        $html = (new HtmlRenderer())->render($document);
        self::assertStringContainsString("<td>\n  <p>one</p>\n  <p>two</p>\n</td>", $html);
        self::assertStringContainsString('| one two |', (new MarkdownRenderer())->render($document));
        self::assertStringContainsString('one two', (new PlainTextRenderer())->render($document));
        self::assertStringContainsString('one two', (new AnsiRenderer())->render($document));

        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();
        self::assertStringContainsString('| one two |', $writer->render($document));
        $report = $writer->finishConversionDiagnosticCollection();
        self::assertSame(1, $report['totalDiagnostics']);
        self::assertSame('field-unspellable', $report['diagnostics'][0]['code']);
        self::assertSame('table_cell', $report['diagnostics'][0]['node']);
        self::assertSame('blocks', $report['diagnostics'][0]['field']);
    }

    public function testAListAndNestedBreakFlattenWithoutListMarkers(): void
    {
        $wire = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'table', 'rows' => [
                        [
                            'type' => 'table_row', 'cells' => [
                                [
                                    'type' => 'table_cell',
                                    'header' => false,
                                    'blocks' => [
                                        [
                                            'type' => 'list',
                                            'ordered' => false,
                                            'tight' => true,
                                            'items' => [
                                                [
                                                    'type' => 'list_item', 'children' => [
                                                        [
                                                            'type' => 'paragraph', 'children' => [
                                                                [
                                                                    'type' => 'emphasis', 'children' => [
                                                                        ['type' => 'text', 'value' => 'one'],
                                                                        ['type' => 'hard_break'],
                                                                        ['type' => 'text', 'value' => 'two'],
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $document = (new AstCodec())->decode($wire);
        self::assertStringContainsString('one two', (new PlainTextRenderer())->render($document));
        self::assertStringContainsString('one two', (new AnsiRenderer())->render($document));
        // PART 11 section 9a: the Markdown target keeps the hard break as `<br>`.
        self::assertStringContainsString('| *one<br>two* |', (new MarkdownRenderer())->render($document));
    }

    public function testBlockCellDiagnosticsCountOriginalNodesOnce(): void
    {
        $cell = [
            'type' => 'table_cell',
            'header' => false,
            'blocks' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'small_caps', 'children' => [['type' => 'text', 'value' => 'Nato']]],
                    ],
                ],
            ],
        ];
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'table',
                    'rows' => [
                        ['type' => 'table_row', 'cells' => [$cell, $cell]],
                    ],
                ],
            ],
        ]);
        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();
        $writer->render($document);
        $report = $writer->finishConversionDiagnosticCollection();

        self::assertSame(4, $report['totalDiagnostics']);
        self::assertSame(2, count(array_filter($report['diagnostics'], static fn (array $item): bool => $item['node'] === 'small_caps')));
        self::assertSame(0, $writer->finishConversionDiagnosticCollection()['totalDiagnostics']);
    }

    public function testRawBlocksAreOmittedAndCodePayloadsHaveOneBlockSeparator(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'table',
                    'rows' => [
                        [

                            'type' => 'table_row',
                            'cells' => [
                                [
                                    'type' => 'table_cell',
                                    'header' => false,
                                    'blocks' => [
                                        ['type' => 'raw_block', 'format' => 'html', 'content' => '<b>x</b>'],
                                        ['type' => 'code_block', 'content' => "a\nb\n"],
                                        ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'c']]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        foreach ([new MarkdownRenderer(), new PlainTextRenderer(), new CarveRenderer()] as $renderer) {
            $output = $renderer->render($document);
            self::assertStringContainsString('a b c', $output);
            self::assertStringNotContainsString('<b>x</b>', $output);
            self::assertStringNotContainsString('a b  c', $output);
        }
    }

    public function testAnEmptyBlockCellHasNoExtraHtmlLine(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'table',
                    'rows' => [
                        [

                            'type' => 'table_row',
                            'cells' => [
                                [
                                    'type' => 'table_cell',
                                    'header' => false,
                                    'blocks' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('<td></td>', (new HtmlRenderer())->render($document));
    }

    public function testAnEmptyCodeSpanInANonfinalBlockCellIsUnspellable(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'table',
                    'rows' => [
                        [

                            'type' => 'table_row',
                            'cells' => [
                                [

                                    'type' => 'table_cell',
                                    'header' => false,
                                    'blocks' => [
                                        ['type' => 'paragraph', 'children' => [['type' => 'code', 'value' => '']]],
                                    ],
                                ],
                                ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'text', 'value' => 'next']]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }
}
