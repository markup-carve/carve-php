<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

final class SourceLayout
{
    /**
     * @param string $source
@param array<string, mixed> $ast @return array<string, mixed>
     */
    public static function build(string $source, array $ast): array
    {
        $chars = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $byteAt = static fn (int $offset): int => strlen(implode('', array_slice($chars, 0, $offset)));
        $nodes = [];
        self::walk($ast, '', $byteAt, $nodes);
        usort($nodes, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        preg_match_all('/\r\n|\r|\n/', $source, $matches);
        $kinds = [];
        foreach ($matches[0] as $ending) {
            $kinds[$ending === "\r\n" ? 'crlf' : ($ending === "\r" ? 'cr' : 'lf')] = true;
        }
        $lineEndings = $kinds === [] ? 'none' : (count($kinds) > 1 ? 'mixed' : array_key_first($kinds));

        return [
            'version' => 1,
            'encoding' => 'utf-8',
            'source' => $source,
            'lineEndings' => $lineEndings,
            'bom' => str_starts_with($source, "\xEF\xBB\xBF"),
            'nodes' => $nodes,
        ];
    }

    /**
     * @param mixed $value @param callable(int): int $byteAt @param list<array<string, int|string>> $nodes
     * @param array $nodes
     * @param callable $byteAt
     * @param string $path
     */
    private static function walk(mixed $value, string $path, callable $byteAt, array &$nodes): void
    {
        if (!is_array($value)) {
            return;
        }
        $pos = $value['pos'] ?? null;
        if (is_array($pos) && is_int($pos['startOffset'] ?? null) && is_int($pos['endOffset'] ?? null)) {
            $nodes[] = ['path' => $path, 'startByte' => $byteAt($pos['startOffset']), 'endByte' => $byteAt($pos['endOffset'])];
        }
        foreach ($value as $key => $child) {
            if ($key === 'pos' || (!is_array($child))) {
                continue;
            }
            $escaped = str_replace(['~', '/'], ['~0', '~1'], (string)$key);
            self::walk($child, $path . '/' . $escaped, $byteAt, $nodes);
        }
    }
}
