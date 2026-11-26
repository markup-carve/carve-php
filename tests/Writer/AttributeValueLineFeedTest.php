<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\Writer\CarveWriteError;
use MarkupCarve\Carve\Writer\Writer;
use MarkupCarve\Carve\Writer\WriteResult;
use PHPUnit\Framework\TestCase;

final class AttributeValueLineFeedTest extends TestCase
{
    public function testPinnedClauseCases(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $seen = [];
        foreach ($spec['cases'] as $case) {
            if (!in_array($case['id'], ['CC-2010', 'CC-2011', 'CC-2012'], true)) {
                continue;
            }
            $seen[] = $case['id'];
            $tree = $case['tree'] ?? null;
            $result = $tree === null
                ? HtmlImporter::import($case['html'])
                : Writer::writeWithDiagnostics($tree);
            self::assertSame($case['expected']['carve'], $result->source, $case['id']);
            self::assertSame(
                $case['expected']['diagnostics'],
                array_map(
                    static fn ($d): array => [
                        'code' => $d->code,
                        'path' => $d->path,
                        'channel' => $d->channel,
                        'severity' => $d->severity,
                    ],
                    $result->diagnostics,
                ),
                $case['id'],
            );
            if ($tree !== null) {
                self::assertSame("x\n\ny", $tree['children'][0]['children'][0]['attrs']['keyValues'][0][1]);
                try {
                    Writer::write($tree);
                    self::fail('A source-only write must throw on the attribute loss');
                } catch (CarveWriteError $error) {
                    self::assertSame('field-unspellable', $error->diagnostics[0]->code);
                }
            }
        }
        self::assertSame(['CC-2010', 'CC-2011', 'CC-2012'], $seen);
    }

    public function testAttributeInCaptionUsesTheSameSpellingAndPath(): void
    {
        $span = [
            'type' => 'span',
            'attrs' => ['keyValues' => [['title', "a\nb"]]],
            'children' => [['type' => 'text', 'value' => 'y']],
        ];
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [['type' => 'text', 'value' => 'x']],
                    'caption' => [

                        'type' => 'caption',
                        'children' => [
                            [
                                'type' => 'paragraph',
                                'children' => [$span],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = Writer::writeWithDiagnostics($tree);
        self::assertSame("x\n^ [y]{title=\"a b\"}\n", $result->source);
        self::assertSame(['field-unspellable'], $result->codes());
        self::assertSame(
            '/children/0/caption/children/0/children/0/attrs/keyValues/0/1',
            $result->diagnostics[0]->path,
        );
    }

    public function testAnAttributeFieldAlreadyLostDoesNotReportItsValueAgain(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'table',
                    'columns' => [['type' => 'table_column']],
                    'children' => [
                        [
                            'type' => 'table_body',
                            'attrs' => ['keyValues' => [['title', "a\nb"]]],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];

        $result = Writer::writeWithDiagnostics($tree);
        self::assertSame(['field-unspellable'], $result->codes());
        self::assertSame('/children/0/children/0/attrs', $result->diagnostics[0]->path);
        self::assertStringNotContainsString("a\nb", $result->source);
    }

    public function testAnUnrelatedLossOnTheParentDoesNotHideTheAttributeLoss(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'image',
                            'src' => 'u',
                            'alt' => 'a',
                            'attrs' => ['keyValues' => [['title', "a\nb"]]],
                        ],
                    ],
                ],
            ],
        ];

        $result = Writer::writeWithDiagnostics($tree);
        self::assertSame("![a](u){title=\"a b\"}\n", $result->source);
        self::assertSame(
            ['/children/0', '/children/0/children/0/attrs/keyValues/0/1'],
            array_map(static fn ($d): ?string => $d->path, $result->diagnostics),
        );
    }

    public function testResolvedIncludeChildrenAreNotWrittenOrReported(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'include',
                    'path' => 'a.crv',
                    'children' => [
                        [
                            'type' => 'paragraph',
                            'children' => [
                                [
                                    'type' => 'span',
                                    'attrs' => ['keyValues' => [['title', "a\nb"]]],
                                    'children' => [['type' => 'text', 'value' => 'x']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = Writer::writeWithDiagnostics($tree);
        self::assertSame("{{ a.crv }}\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    public function testUnusedExtensionChildrenDoNotReportAttributeLoss(): void
    {
        $span = [
            'type' => 'span',
            'attrs' => ['keyValues' => [['title', "a\nb"]]],
            'children' => [['type' => 'text', 'value' => 'x']],
        ];
        $extension = [
            'type' => 'block_extension',
            'name' => 'org.example.n',
            'version' => '1',
            'children' => [['type' => 'paragraph', 'children' => [$span]]],
            'fallback' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'safe']]]],
        ];
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [$extension]];

        $result = Writer::writeWithDiagnostics($tree);
        self::assertSame("safe\n", $result->source);
        self::assertSame(['structure-unspellable'], $result->codes());
        self::assertSame('/children/0', $result->diagnostics[0]->path);
    }

    /**
     * C2-W-158: the line-feed losses stand in path order among the other entries of the record.
     */
    public function testLineFeedLossesStandInPathOrder(): void
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $cell = static fn (array $children): array => ['type' => 'table_cell', 'header' => false, 'children' => $children];
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                ['type' => 'paragraph', 'attrs' => ['keyValues' => [['title', "a\nb"]]], 'children' => [$text('p')]],
                [
                    'type' => 'table',
                    'columns' => [['type' => 'table_column'], ['type' => 'table_column']],
                    'children' => [
                        [
                            'type' => 'table_body',
                            'children' => [
                                [
                                    'type' => 'table_row',
                                    'children' => [
                                        $cell([['type' => 'cell_paragraph', 'children' => [$text('x')]]]),
                                        $cell([
                                            ['type' => 'paragraph', 'children' => [$text('a')]],
                                            ['type' => 'paragraph', 'children' => [$text('b')]],
                                        ]),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        self::assertSame(
            [
                'field-unspellable /children/0/attrs/keyValues/0/1',
                'structure-unspellable /children/1/children/0/children/0/children/1',
            ],
            self::entries(Writer::writeWithDiagnostics($tree)),
        );
    }

    /**
     * C2-L-011: a node's fields before its children, its caption after them; root fields before every node.
     */
    public function testFieldsChildrenAndCaptionInDocumentOrder(): void
    {
        $span = static fn (string $value): array => [
            'type' => 'span',
            'attrs' => ['keyValues' => [['title', "a\nb"]]],
            'children' => [['type' => 'text', 'value' => $value]],
        ];
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'lang' => 'de',
            'children' => [
                [
                    'type' => 'admonition',
                    'kind' => 'note',
                    'attrs' => ['keyValues' => [['title', "x\ny"]]],
                    'title' => [['type' => 'text', 'value' => 't']],
                    'children' => [['type' => 'paragraph', 'children' => [$span('c')]]],
                ],
                [
                    'type' => 'block_quote',
                    'children' => [['type' => 'paragraph', 'children' => [$span('q')]]],
                    'caption' => [
                        'type' => 'caption',
                        'children' => [['type' => 'paragraph', 'children' => [$span('k')]]],
                    ],
                ],
            ],
        ];

        self::assertSame(
            [
                'field-unspellable /lang',
                'field-unspellable /children/0/attrs/keyValues/0/1',
                'field-unspellable /children/0/children/0/children/0/attrs/keyValues/0/1',
                'field-unspellable /children/1/children/0/children/0/attrs/keyValues/0/1',
                'field-unspellable /children/1/caption/children/0/children/0/attrs/keyValues/0/1',
            ],
            self::entries(Writer::writeWithDiagnostics($tree)),
        );
    }

    /**
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     *
     * @return array<string>
     */
    private static function entries(WriteResult $result): array
    {
        return array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics);
    }
}
