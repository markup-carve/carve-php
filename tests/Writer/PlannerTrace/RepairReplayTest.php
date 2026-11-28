<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer\PlannerTrace;

use MarkupCarve\Carve\Writer\Check\Node;
use MarkupCarve\Carve\Writer\Check\Passes;
use MarkupCarve\Carve\Writer\Check\TreeGroupWriter;
use MarkupCarve\Carve\Writer\Check\TreeSpell;
use MarkupCarve\Carve\Writer\Check\WriterState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Random trees of paragraphs (`fixtures/carve-js-repairs.jsonl`) written group by group through `Writer\Check\Passes`
 * must give the source and the loss of carve-js `writeTreeWithStats` on its repair path. Records in `repairs-held.txt`
 * do not match yet; a held record that matches fails, so the list only shrinks.
 */
final class RepairReplayTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array<string, mixed>>, string, bool}>
     */
    public static function records(): iterable
    {
        foreach (explode("\n", (string)file_get_contents(__DIR__ . '/fixtures/carve-js-repairs.jsonl')) as $line) {
            $record = $line === '' ? null : json_decode($line, true);
            if (!is_array($record) || !is_string($record['id'] ?? null) || !is_array($record['children'] ?? null)) {
                continue;
            }
            $children = [];
            foreach ($record['children'] as $child) {
                if (is_array($child)) {
                    $children[] = self::fields($child);
                }
            }

            $source = is_string($record['source'] ?? null) ? $record['source'] : '';

            yield $record['id'] => [$record['id'], $children, $source, ($record['lost'] ?? false) === true];
        }
    }

    /**
     * @param string $id
     * @param list<array<string, mixed>> $children
     * @param string $source
     * @param bool $lost
     */
    #[DataProvider('records')]
    public function testRepairMatchesCarveJs(string $id, array $children, string $source, bool $lost): void
    {
        $actual = self::repair($children);
        $expected = [$source, $lost];
        if (isset(self::held()[$id])) {
            $this->assertNotSame($expected, $actual, "{$id} now matches carve-js: remove it from repairs-held.txt");

            return;
        }
        $this->assertSame($expected, $actual, $id);
    }

    /**
     * Each paragraph is a group of its own, as `groupEnd` of carve-js makes it behind a blank line.
     *
     * @param list<array<string, mixed>> $children
     *
     * @return array{string, bool}
     */
    private static function repair(array $children): array
    {
        $document = Node::mirror(['type' => 'document', 'children' => $children]);
        $written = [];
        $lost = false;
        foreach ($document->children() as $at => $group) {
            $w = new WriterState(new TreeSpell());
            $w->groupWriter = new TreeGroupWriter();
            Passes::writeGroup($w, $document, [$group], $at === 0);
            $written[] = implode("\n", $w->lines);
            $lost = $lost || $w->lost !== [];
        }

        return [implode("\n\n", $written) . "\n", $lost];
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function fields(array $value): array
    {
        $fields = [];
        foreach ($value as $key => $item) {
            $fields[(string)$key] = $item;
        }

        return $fields;
    }

    /**
     * @return array<string, true>
     */
    private static function held(): array
    {
        $held = [];
        foreach (explode("\n", (string)file_get_contents(__DIR__ . '/repairs-held.txt')) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $held[$line] = true;
            }
        }

        return $held;
    }
}
