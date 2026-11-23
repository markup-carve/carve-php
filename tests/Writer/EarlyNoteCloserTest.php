<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-125, case eight (carve#6471): a text `^[` whose span keeps a caret pair of the tree ordinary (C2-I-089) stays
 * bare, and the bare `]` that closed it early takes the backslash.
 */
final class EarlyNoteCloserTest extends TestCase
{
    public function testPinnedClauseCases(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $ids = ['CC-1753', 'CC-1754', 'CC-1755', 'CC-1756'];
        $seen = [];
        foreach ($spec['cases'] as $case) {
            if (!in_array($case['id'], $ids, true)) {
                continue;
            }
            $seen[] = $case['id'];
            $result = $case['kind'] === 'write'
                ? Carve::writeWithDiagnostics($case['tree'], null, $case['options'])
                : Carve::formatWithDiagnostics($case['input'], $case['options']);
            self::assertSame($case['expected']['carve'], $result->source, $case['id']);
            self::assertSame([], $result->diagnostics, $case['id'] . ' diagnostics');
        }
        self::assertSame($ids, $seen);
    }

    /**
     * Each source is the form carve-js writes; it reads back as its own tree.
     *
     * @return array<string, array{string}>
     */
    public static function canonical(): array
    {
        return [
            'collapsed reference' => ['^[ \]} [^[b]\]][]'],
            'two early closers' => ['x ^[a \] b \] [^[b] c][] z'],
            'bracket pair inside the note' => ['- ^[ [x] \]} [^a[b]][].'],
            'inline link with a lone bracket' => ['^[ \] [^[b]\]](/u) z'],
            'inline link behind a strong' => ['^[a \] *s* [^[b]](/u).'],
            'caret of link text inside the span' => ['^[a \] [^[x] y](/u)'],
            'quote' => ['> x ^[a *b* \]} `k` [^a[b]][] z'],
            'table cell' => ['| ^[ \] t [^[b] c][] |'],
            'two text notes' => ['^[a \] ^[b \] [^[x]][]'],
            'real note before' => ['^[real] ^[a \] [^[x] y](/u)'],
        ];
    }

    #[DataProvider('canonical')]
    public function testFormatKeepsTheCanonicalForm(string $source): void
    {
        $result = Carve::formatWithDiagnostics($source);

        self::assertSame($source . "\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * A supplied tree may hold the opener across two text nodes.
     */
    #[DataProvider('canonical')]
    public function testOpenerSplitAcrossTextNodes(string $source): void
    {
        $tree = json_decode(Carve::toJson($source), true, 4096, JSON_THROW_ON_ERROR);
        self::assertTrue(self::split($tree), $source);

        $result = Carve::writeWithDiagnostics($tree);

        self::assertSame($source . "\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * Splits the first text holding `^[` between its caret and its bracket.
     *
     * @param array<string, mixed> $node
     */
    private static function split(array &$node): bool
    {
        if (!is_array($node['children'] ?? null)) {
            return false;
        }
        foreach ($node['children'] as $k => &$child) {
            $value = $child['value'] ?? null;
            if (($child['type'] ?? '') === 'text' && is_string($value) && str_contains($value, '^[')) {
                $at = strpos($value, '^[') + 1;
                array_splice($node['children'], $k, 1, [
                    ['type' => 'text', 'value' => substr($value, 0, $at)],
                    ['type' => 'text', 'value' => substr($value, $at)],
                ]);

                return true;
            }
            if (is_array($child) && self::split($child)) {
                return true;
            }
        }

        return false;
    }
}
