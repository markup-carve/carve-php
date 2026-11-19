<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Diagnostics;

use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;
use SplObjectStorage;

/**
 * The parse findings of one parse. Each is noted with the node it is about; pointers are found once, at the end.
 */
final class Findings
{
    /**
     * @var array<array{0: string, 1: \MarkupCarve\Carve\Node|null, 2: string|null, 3: int, 4: int, 5: string|null}>
     */
    private array $notes = [];

    /**
     * @param string $code A code of the catalog (`lint-rules.json`).
     * @param \MarkupCarve\Carve\Node|null $node Null for the document.
     * @param string|null $file The id of the included file the range counts in.
     * @param int $start The range when it is not that of the node.
     * @param int $end
     * @param string|null $message A message with its slots filled; the catalog text otherwise.
     *
     * @return void
     */
    public function add(string $code, ?Node $node, ?string $file = null, int $start = -1, int $end = -1, ?string $message = null): void
    {
        $this->notes[] = [$code, $node, $file, $start, $end, $message];
    }

    public function count(): int
    {
        return count($this->notes);
    }

    public function merge(self $other, ?string $file): void
    {
        foreach ($other->notes as [$code, $node, $oldFile, $start, $end, $message]) {
            $this->notes[] = [$code, $node, $oldFile ?? $file, $start, $end, $message];
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node $doc
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public function finish(Node $doc): array
    {
        if ($this->notes === []) {
            return [];
        }
        $paths = self::paths($doc);
        $out = [];
        foreach ($this->notes as [$code, $node, $file, $start, $end, $message]) {
            [$severity, $channel] = Tables::DIAGNOSTICS[$code];
            if ($start < 0 && $node !== null && $node->start >= 0) {
                $start = $node->start;
                $end = $node->end;
            }
            $path = $node === null ? '' : ($paths[$node] ?? null);
            $out[] = new Diagnostic($code, $severity, $channel, $message ?? Message::fill($code), $path, $start, $end, $file);
        }

        return $out;
    }

    /**
     * The pointer of every node, in one walk over every node slot.
     *
     * @param \MarkupCarve\Carve\Node $doc
     *
     * @return \SplObjectStorage<\MarkupCarve\Carve\Node, string>
     */
    public static function paths(Node $doc): SplObjectStorage
    {
        /** @var \SplObjectStorage<\MarkupCarve\Carve\Node, string> $paths */
        $paths = new SplObjectStorage();
        $stack = [[$doc, '']];
        while ($stack !== []) {
            [$node, $path] = array_pop($stack);
            $paths[$node] = $path;
            foreach ($node->children ?? [] as $i => $child) {
                $stack[] = [$child, $path . '/children/' . $i];
            }
            foreach ($node->f as $key => $value) {
                if ($value instanceof Node) {
                    $stack[] = [$value, $path . '/' . $key];
                } elseif (is_array($value) && $value !== [] && array_is_list($value)) {
                    foreach ($value as $i => $item) {
                        if ($item instanceof Node) {
                            $stack[] = [$item, $path . '/' . $key . '/' . $i];
                        }
                    }
                }
            }
        }

        return $paths;
    }
}
