<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use InvalidArgumentException;

final class Provenance
{
    /**
     * @param array<string, mixed> $ast
     * @param array<string, mixed> $layout
     * @param string $uri
     *
     * @return array{version: 1, sources: list<array<string, mixed>>, nodes: list<array<string, mixed>>}
     */
    public static function fromSourceLayout(array $ast, array $layout, string $uri): array
    {
        if ($uri === '' || !is_array($layout['nodes'] ?? null)) {
            throw new InvalidArgumentException('A source URI and source layout are required.');
        }
        $validPaths = array_fill_keys(SidecarPath::nodePaths($ast), true);
        $nodes = [];
        foreach ($layout['nodes'] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_int($entry['startByte'] ?? null) || !is_int($entry['endByte'] ?? null)) {
                throw new InvalidArgumentException('Invalid source layout node.');
            }
            try {
                SidecarPath::node($ast, $entry['path'], $validPaths);
            } catch (InvalidArgumentException) {
                continue;
            }
            $nodes[] = ['path' => $entry['path'], 'source' => 's0', 'origin' => 'authored', 'startByte' => $entry['startByte'], 'endByte' => $entry['endByte']];
        }

        return self::create($ast, [['id' => 's0', 'uri' => $uri]], $nodes);
    }

    /**
     * @param array<string, mixed> $ast
     * @param list<array<string, mixed>> $sources
     * @param list<array<string, mixed>> $nodes
     *
     * @return array{version: 1, sources: list<array<string, mixed>>, nodes: list<array<string, mixed>>}
     */
    public static function create(array $ast, array $sources, array $nodes): array
    {
        $sidecar = ['version' => 1, 'sources' => $sources, 'nodes' => $nodes];
        self::read($ast, $sidecar);

        return $sidecar;
    }

    /**
     * @param array<string, mixed> $ast
     * @param array<string, mixed> $sidecar
     *
     * @throws \InvalidArgumentException
     */
    public static function read(array $ast, array $sidecar): void
    {
        SidecarPath::fields($sidecar, ['version', 'sources', 'nodes'], ['version', 'sources', 'nodes']);
        if ($sidecar['version'] !== 1 || !is_array($sidecar['sources']) || !array_is_list($sidecar['sources']) || !is_array($sidecar['nodes']) || !array_is_list($sidecar['nodes'])) {
            throw new InvalidArgumentException('Unsupported or malformed provenance sidecar.');
        }
        $sources = [];
        foreach ($sidecar['sources'] as $source) {
            if (!is_array($source)) {
                throw new InvalidArgumentException('Invalid provenance source.');
            }
            SidecarPath::fields($source, ['id'], ['id', 'uri', 'format', 'parent']);
            $id = $source['id'];
            if (!is_string($id) || $id === '' || isset($sources[$id])) {
                throw new InvalidArgumentException('Invalid or duplicate provenance source id.');
            }
            foreach (['uri', 'format', 'parent'] as $field) {
                if (array_key_exists($field, $source) && !SidecarPath::nonempty($source[$field])) {
                    throw new InvalidArgumentException('Provenance source field must be a nonempty string.');
                }
            }
            $sources[$id] = $source;
        }
        foreach ($sources as $id => $source) {
            $seen = [$id => true];
            while (isset($source['parent'])) {
                $parent = $source['parent'];
                if (!is_string($parent) || !isset($sources[$parent]) || isset($seen[$parent])) {
                    throw new InvalidArgumentException('Provenance source ancestry is missing or cyclic.');
                }
                $seen[$parent] = true;
                $source = $sources[$parent];
            }
        }
        $paths = [];
        $validPaths = array_fill_keys(SidecarPath::nodePaths($ast), true);
        foreach ($sidecar['nodes'] as $node) {
            if (!is_array($node)) {
                throw new InvalidArgumentException('Invalid provenance node.');
            }
            SidecarPath::fields($node, ['path', 'source', 'origin'], ['path', 'source', 'origin', 'startByte', 'endByte', 'step']);
            $path = $node['path'];
            $source = $node['source'];
            if (!is_string($path) || !is_string($source) || $source === '' || !isset($sources[$source]) || !in_array($node['origin'], ['authored', 'generated'], true) || isset($paths[$path])) {
                throw new InvalidArgumentException('Invalid provenance node path, source, or origin.');
            }
            SidecarPath::node($ast, $path, $validPaths);
            if (array_key_exists('startByte', $node) !== array_key_exists('endByte', $node)) {
                throw new InvalidArgumentException('Provenance byte range needs both endpoints.');
            }
            if (array_key_exists('startByte', $node) && (!is_int($node['startByte']) || !is_int($node['endByte']) || $node['startByte'] < 0 || $node['endByte'] < $node['startByte'])) {
                throw new InvalidArgumentException('Invalid provenance byte range.');
            }
            if (array_key_exists('step', $node) && !SidecarPath::nonempty($node['step'])) {
                throw new InvalidArgumentException('Provenance step must be a nonempty string.');
            }
            $paths[$path] = true;
        }
    }
}
