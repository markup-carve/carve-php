<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\WriterGate;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#6308: an attribute line of an ordered list at a style change keeps the blank line above it.
 */
final class AttributeListBoundaryTest extends TestCase
{
    public function testPinnedClauseCases(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $ids = ['CC-1480', 'CC-1481', 'CC-1482', 'CC-1483', 'CC-1484'];
        $seen = [];
        foreach ($spec['cases'] as $case) {
            if (!in_array($case['id'], $ids, true)) {
                continue;
            }
            $seen[] = $case['id'];
            $result = Carve::formatWithDiagnostics($case['input'], $case['options']);
            self::assertSame($case['expected']['carve'], $result->source, $case['id']);
            self::assertSame([], $result->diagnostics, $case['id'] . ' diagnostics');
            self::assertSame(['failure' => null, 'loss' => false, 'refused' => false], WriterGate::gates($case['input']), $case['id']);
        }
        self::assertSame($ids, $seen);
    }

    public function testOpaqueLastBlockKeepsTheZeroBlankBoundary(): void
    {
        $source = "v. ```\n   x\n   ```\n\n{x}\nvi. y\n";

        self::assertSame("v. ```\n   x\n   ```\n{x}\nvi. y\n", Carve::format($source));
    }

    /**
     * C2-W-002, C2-W-003 over styles, attribute lines, container prefixes and contents (the carve-js sweep).
     */
    public function testBoundaryVariantsRoundTrip(): void
    {
        $first = ['v. x', 'a. x', 'i. x', 'A. x', 'V. x', '1. x'];
        $second = ['vi. y', '1. y', 'b. y', 'I. y', '2. y'];
        $attrs = ['{x}', '{#i}', '{.c}', '{k=v}', '{#i .c}', '{start=7}'];
        $prefixes = ['', '> ', "- q\n\n  "];
        $contents = ['y', '[y](u)'];
        $failures = [];
        for ($at = 0; $at < 1080; $at++) {
            $left = $first[intdiv($at, 180)];
            $right = str_replace(' y', ' ' . $contents[$at % 2], $second[intdiv($at, 36) % 5]);
            $attr = $attrs[intdiv($at, 6) % 6];
            $input = match ($prefixes[intdiv($at, 2) % 3]) {
                '' => "$left\n\n$attr\n$right\n",
                '> ' => "> $left\n>\n> $attr\n> $right\n",
                default => "- q\n\n  $left\n\n  $attr\n  $right\n",
            };
            $gates = WriterGate::gates($input);
            if ($gates['failure'] !== null || $gates['refused']) {
                $failures[] = json_encode($input) . ' ' . ($gates['failure'] ?? 'refused');
            }
        }
        self::assertSame([], $failures);
    }
}
