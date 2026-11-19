<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TreeMigrationTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $case) {
            if (($case['kind'] ?? null) === 'migrate' && is_string($case['id'] ?? null)) {
                $out[$case['id']] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testMigrationClause(array $case): void
    {
        $tree = self::object($case['tree'] ?? null);
        $options = self::object($case['options'] ?? null);
        $expected = $case['expected'];
        if (!is_array($expected)) {
            self::fail('migration case has no expectation');
        }
        $result = Carve::migrateTree($tree, $options);
        $document = $result['document'];
        $expectedTree = $expected['tree'] ?? null;
        if (is_array($document) && is_array($expectedTree) && !isset($expectedTree['srcByteLength'])) {
            unset($document['srcByteLength']);
        }
        self::assertSame(self::sorted($expected['tree']), self::sorted($document));
        if (isset($expected['diagnosticsComplete'])) {
            self::assertSame($expected['diagnostics'], $result['diagnostics']);
        }
    }

    public function testEnvelopeAndSpellingLayout(): void
    {
        $input = [
            'astVersion' => '1.2',
            'vocabulary' => ['name' => 'custom'],
            'document' => [
                'type' => 'document',
                'children' => [
                    [
                        'type' => 'block_quote',
                        'fenced' => true,
                        'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]]],
                    ],
                ],
            ],
        ];
        $result = Carve::migrateTree($input);
        self::assertSame('2.0', self::at($result, ['envelope', 'astVersion']));
        self::assertSame($result['document'], self::at($result, ['envelope', 'document']));
        self::assertSame($input['vocabulary'], self::at($result, ['envelope', 'vocabulary']));
        self::assertSame([
            'path' => '/children/0',
            'startByte' => 0,
            'endByte' => 0,
            'fenced' => true,
        ], self::at($result, ['layout', 'nodes', 0]));
    }

    public function testLegacyTableGroupsAndPreservesWrapFact(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '1.2', 'children' => [
                [
                    'type' => 'table', 'rows' => [
                        [
                            'type' => 'table_row', 'cells' => [
                                [
                                    'type' => 'table_cell',
                                    'header' => false, 'children' => [
                                        ['type' => 'text', 'value' => 'a'],
                                        ['type' => 'cell_wrap', 'line' => 1],
                                        ['type' => 'text', 'value' => 'b'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $result = Carve::migrateTree($tree);
        self::assertSame('table_body', self::at($result, ['document', 'children', 0, 'children', 0, 'type']));
        self::assertSame('a b', self::at($result, [
            'document', 'children', 0, 'children', 0, 'children', 0, 'children', 0, 'children',
            0, 'children', 0, 'value',
        ]));
        self::assertSame([
            [
                'line' => 1,
                'path' => '/children/0/children/0/children/0/children/0/children/0/children/0',
                'offset' => 2,
            ],
        ], self::at($result, ['layout', 'nodes', 1, 'wraps']));
    }

    public function testExplicitHeadBodyAndFootGroupsSurviveMigration(): void
    {
        $row = static fn (string $value): array => [
            'type' => 'table_row', 'cells' => [
                [
                    'type' => 'table_cell',
                    'header' => false,
                    'children' => [['type' => 'text', 'value' => $value]],
                ],
            ],
        ];
        $tree = [
            'type' => 'document',
            'astVersion' => '1.2', 'children' => [
                [
                    'type' => 'table',
                    'rows' => [$row('h'), $row('a'), $row('b'), $row('f')],
                    'rowGroups' => [
                        'headRows' => 1, 'bodies' => [
                            ['headRows' => 0, 'bodyRows' => 1, 'rowHeadColumns' => 1],
                            ['headRows' => 0, 'bodyRows' => 1],
                        ], 'footRows' => 1,
                    ],
                ],
            ],
        ];
        $groups = Carve::migrateTree($tree)['document']['children'][0]['children'];
        self::assertSame(['table_head', 'table_body', 'table_body', 'table_foot'], array_column($groups, 'type'));
        self::assertSame(1, $groups[1]['rowHeadColumns']);
        self::assertCount(1, $groups[3]['children']);
    }

    public function testSourceLayoutDoesNotDescribeAnotherStoredTree(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '1.2', 'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [['type' => 'text', 'value' => 'edited']],
                ],
            ],
        ];
        $result = Carve::migrateTree($tree, ['source' => "original\n"]);
        self::assertSame('', $result['layout']['source']);
        self::assertSame([], $result['layout']['nodes']);
    }

    /**
     * @param array<string, mixed> $value
     * @param array<int, int|string> $path
     */
    private static function at(array $value, array $path): mixed
    {
        $current = $value;
        foreach ($path as $step) {
            if (!is_array($current)) {
                return null;
            }
            $current = $current[$step] ?? null;
        }

        return $current;
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            self::fail('migration case expected a JSON object');
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                self::fail('migration case expected string keys');
            }
            $out[$key] = $item;
        }

        return $out;
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
