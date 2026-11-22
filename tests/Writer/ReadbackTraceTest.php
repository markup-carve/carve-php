<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Writer\ReadbackRound;
use MarkupCarve\Carve\Writer\RepairAlignment;
use MarkupCarve\Carve\Writer\RepairPlanner;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadbackTraceTest extends TestCase
{
    #[DataProvider('escapedBlockOpeners')]
    public function testNormalWriteDoesNotEscapeContinuationAfterEscapedOpener(string $input): void
    {
        self::assertSame($input, Carve::formatWithDiagnostics($input)->source);
    }

    public static function escapedBlockOpeners(): iterable
    {
        yield 'heading' => ["\\# hi\n+\n"];
        yield 'div fence' => ["\\::: note\n+\n"];
        yield 'colon fence' => ["\\::::\n+\n"];
    }

    public function testTracePreservesLiteralTableSpanMark(): void
    {
        $input = "|= Name |= Qty |\n| A | \\^ |\n";
        $parsed = Carve::parseWithLayout($input);
        $tree = TreeEncoder::decodedForWriter($parsed->tree);
        $marked = Writer::bareCandidate($tree, $parsed->layout);
        $plain = Writer::bareCandidate($tree, $parsed->layout, [], [], false);
        self::assertSame($plain['source'], $marked['source']);
        self::assertSame($input, $marked['source']);
    }

    public function testTraceDoesNotChangeAParagraphLineMarker(): void
    {
        $input = "/a/ [x] /b/\n\n| a \\|\n";
        $parsed = Carve::parseWithLayout($input);
        $tree = TreeEncoder::decodedForWriter($parsed->tree);
        $marked = Writer::bareCandidate($tree, $parsed->layout);
        $plain = Writer::bareCandidate($tree, $parsed->layout, [], [], false);
        self::assertSame($plain['source'], $marked['source']);
        self::assertSame("/a/ [x] /b/\n\n\\| a |\n", $marked['source']);
        self::assertArrayHasKey((int)strpos($marked['source'], '| a'), $marked['text']);
    }

    public function testTracePositionsFollowTheUnmarkedSourceAfterAnInsertedEscape(): void
    {
        $candidate = [
            'source' => 'a|b',
            'text' => [1 => ['/children/0/children/0', 0]],
            'nodes' => [['/children/0/children/1', 'emphasis', 1, 3]],
            'ranges' => ['/children/0/children/0' => [1, 2]],
        ];
        $aligned = RepairAlignment::onto($candidate, 'a\\|b');
        self::assertSame('a\\|b', $aligned['source']);
        self::assertSame([2 => ['/children/0/children/0', 0]], $aligned['text']);
        self::assertSame([['/children/0/children/1', 'emphasis', 2, 4]], $aligned['nodes']);
        self::assertSame(['/children/0/children/0' => [2, 3]], $aligned['ranges']);
    }

    public function testTracePreservesPairedFormAfterTrailingWhitespace(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => "a\t"]]],
                        ['type' => 'strong', 'children' => [['type' => 'text', 'value' => '[']]],
                    ],
                ],
            ],
        ];
        $marked = Writer::bareCandidate($tree);
        $plain = Writer::bareCandidate($tree, null, [], [], false);
        self::assertSame($plain['source'], $marked['source']);
        self::assertSame("{/a\t/}*[*\n", $marked['source']);
    }

    #[DataProvider('leadingCaretHosts')]
    public function testTracePreservesLeadingCaretInBracketedLabel(string $type, string $suffix): void
    {
        $label = ['type' => $type, 'children' => [['type' => 'text', 'value' => '^']]];
        if ($type === 'link') {
            $label['href'] = 'u';
        }
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => 'a']]],
                        $label,
                    ],
                ],
            ],
        ];
        $marked = Writer::bareCandidate($tree);
        $plain = Writer::bareCandidate($tree, null, [], [], false);
        self::assertSame($plain['source'], $marked['source']);
        self::assertSame('/a/[\\^]' . $suffix . "\n", $marked['source']);
    }

    public static function leadingCaretHosts(): iterable
    {
        yield 'link' => ['link', '(u)'];
        yield 'span' => ['span', '{}'];
    }

    public function testRepairGroupKeepsLaterLiteralTableSpanMark(): void
    {
        $cases = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        )['cases'];
        foreach ($cases as $case) {
            if (($case['id'] ?? '') !== 'CC-1568') {
                continue;
            }
            $table = "|= Name |= Qty |\n| A | \\^ |\n";
            $input = rtrim($case['input'], "\n") . "\n\n" . $table;
            $expected = rtrim($case['expected']['carve'], "\n") . "\n\n" . $table;
            self::assertSame($expected, Carve::formatWithDiagnostics($input)->source);

            return;
        }
        self::fail('CC-1568 is missing from the pinned clause cases');
    }

    #[DataProvider('terminalBreaks')]
    public function testTracePreservesTerminalBreak(string $break): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a'],
                        ['type' => $break],
                    ],
                ],
            ],
        ];
        $marked = Writer::bareCandidate($tree);
        $plain = Writer::bareCandidate($tree, null, [], [], false);
        self::assertSame($plain['source'], $marked['source']);
    }

    public static function terminalBreaks(): iterable
    {
        yield 'soft break' => ['soft_break'];
        yield 'hard break' => ['hard_break'];
    }

    #[DataProvider('terminalTextLineEndings')]
    public function testTracePreservesTerminalTextLineEndings(string $value): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $value]]],
            ],
        ];
        $marked = Writer::bareCandidate($tree);
        $plain = Writer::bareCandidate($tree, null, [], [], false);
        self::assertSame($plain['source'], $marked['source']);
    }

    public static function terminalTextLineEndings(): iterable
    {
        yield 'line feed' => ["a\n"];
        yield 'two line feeds' => ["a\n\n"];
        yield 'only a line feed' => ["\n"];
        yield 'carriage return and line feed' => ["a\r\n"];
    }

    public function testLiteralControlBytesSurviveTraceDecoding(): void
    {
        $value = "a\x010\x02\x030\x04\x050\x06b";
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $value]]],
            ],
        ];
        $marked = Writer::bareCandidate($tree);
        $plain = Writer::bareCandidate($tree, null, [], [], false);
        self::assertSame($plain['source'], $marked['source']);
        self::assertSame($value . "\n", $marked['source']);
    }

    public function testOrderedRoundsKeepThePinnedNestedSuperscriptBytes(): void
    {
        $cases = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        )['cases'];
        foreach ($cases as $case) {
            if (($case['id'] ?? '') !== 'CC-1568') {
                continue;
            }
            $parsed = Carve::parseWithLayout($case['input']);
            $tree = TreeEncoder::decodedForWriter($parsed->tree);
            $source = (new RepairPlanner($tree, $parsed->layout))->repair();
            self::assertSame($case['expected']['carve'], $source);

            return;
        }
        self::fail('CC-1568 is missing from the pinned clause cases');
    }

    public function testReadbackUsesTheActiveExtensionSet(): void
    {
        $cases = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        )['cases'];
        foreach ($cases as $case) {
            if (($case['id'] ?? '') !== 'CC-1568') {
                continue;
            }
            $source = $case['input'] . ":wiki[Page]\n";
            $result = Carve::formatWithDiagnostics($source, ['extensions' => ['carve.wiki']]);
            self::assertSame([], $result->codes());
            self::assertSame($case['expected']['carve'] . ":wiki[Page]\n", $result->source);

            return;
        }
        self::fail('CC-1568 is missing from the pinned clause cases');
    }

    public function testFailedBareRepairKeepsAValidNormalWrite(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'superscript', 'children' => [['type' => 'text', 'value' => 'a']]],
                        ['type' => 'subscript', 'children' => [['type' => 'text', 'value' => '{']]],
                        ['type' => 'superscript', 'children' => [['type' => 'text', 'value' => '$']]],
                        ['type' => 'text', 'value' => '^'],
                    ],
                ],
            ],
        ];
        $result = Writer::writeWithDiagnostics($tree);
        self::assertSame([], $result->codes());
        self::assertSame("{^a^}{,\\{,}{^$^}^\n", $result->source);
    }

    #[DataProvider('cases')]
    public function testOneReadbackReportsOuterUnexpectedOpener(string $input, int $remainingSpans): void
    {
        $parsed = Carve::parseWithLayout($input);
        $tree = TreeEncoder::decodedForWriter($parsed->tree);
        $candidate = Writer::bareCandidate($tree, $parsed->layout);
        $unmarked = Writer::bareCandidate($tree, $parsed->layout, [], [], false);
        self::assertSame($unmarked['source'], $candidate['source']);

        $first = ReadbackRound::inspect($candidate, $tree);
        self::assertSame(1, array_sum(array_map('count', $first['openers'])));
        self::assertFalse($first['equal']);

        $repaired = Writer::bareCandidate($tree, $parsed->layout, [], $first['openers']);
        $second = ReadbackRound::inspect($repaired, $tree);
        self::assertSame(1, array_sum(array_map('count', $second['openers'])));
        self::assertCount($remainingSpans, $second['moved']);
        self::assertFalse($second['equal']);
    }

    public static function cases(): iterable
    {
        $cases = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        )['cases'];
        foreach ($cases as $case) {
            if (($case['id'] ?? '') === 'CC-1566') {
                yield 'CC-1566' => [$case['input'], 1];
            } elseif (($case['id'] ?? '') === 'CC-1568') {
                yield 'CC-1568' => [$case['input'], 1];
            }
        }
    }
}
