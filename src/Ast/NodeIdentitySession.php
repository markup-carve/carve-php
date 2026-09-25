<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use InvalidArgumentException;

final class NodeIdentitySession
{
    private int $nextId = 0;

    /**
     * @var array<string, string>
     */
    private array $previous = [];

    /**
     * @var array<string, string>
     */
    private array $snapshots = [];

    public readonly string $session;

    public function __construct()
    {
        $this->session = bin2hex(random_bytes(16));
    }

    /**
     * Rebind ids supplied by the editor when it recognizes moved or edited nodes.
     * Unmapped nodes keep an id only when their exact node value stays at the same path.
     *
     * @param array<string, mixed> $ast
     * @param array<string, mixed> $retainedPaths old id => path in the new AST
     *
     * @throws \InvalidArgumentException
     *
     * @return array{version: 1, session: string, nodes: list<array{id: string, path: string}>}
     */
    public function emit(array $ast, array $retainedPaths = []): array
    {
        $paths = SidecarPath::nodePaths($ast);
        $validPaths = array_fill_keys($paths, true);
        $currentSnapshots = [];
        foreach ($paths as $path) {
            $currentSnapshots[$path] = self::snapshot(SidecarPath::node($ast, $path, $validPaths));
        }
        $assigned = [];
        foreach ($retainedPaths as $id => $path) {
            if (!is_string($path) || !isset($this->previous[$id]) || !isset($validPaths[$path]) || isset($assigned[$path])) {
                throw new InvalidArgumentException('Identity rebind must name a previous id and a unique current node path.');
            }
            $assigned[$path] = $id;
        }
        foreach ($this->previous as $id => $path) {
            if (!isset($validPaths[$path]) || isset($assigned[$path]) || isset($retainedPaths[$id])) {
                continue;
            }
            if ($this->snapshots[$id] === $currentSnapshots[$path]) {
                $assigned[$path] = $id;
            }
        }
        $nodes = [];
        $next = [];
        $snapshots = [];
        foreach ($paths as $path) {
            $id = $assigned[$path] ?? 'n' . ++$this->nextId;
            $nodes[] = ['id' => $id, 'path' => $path];
            $next[$id] = $path;
            $snapshots[$id] = $currentSnapshots[$path];
        }
        $this->previous = $next;
        $this->snapshots = $snapshots;

        return ['version' => 1, 'session' => $this->session, 'nodes' => $nodes];
    }

    /**
     * @param array<string, mixed> $ast
     * @param array<string, mixed> $sidecar
     *
     * @throws \InvalidArgumentException
     */
    public static function read(array $ast, array $sidecar): void
    {
        SidecarPath::fields($sidecar, ['version', 'session', 'nodes'], ['version', 'session', 'nodes']);
        if ($sidecar['version'] !== 1 || !SidecarPath::nonempty($sidecar['session']) || !is_array($sidecar['nodes']) || !array_is_list($sidecar['nodes'])) {
            throw new InvalidArgumentException('Unsupported or malformed node identity sidecar.');
        }
        $ids = $paths = [];
        $validPaths = array_fill_keys(SidecarPath::nodePaths($ast), true);
        foreach ($sidecar['nodes'] as $node) {
            if (!is_array($node)) {
                throw new InvalidArgumentException('Invalid node identity entry.');
            }
            SidecarPath::fields($node, ['id', 'path'], ['id', 'path']);
            $id = $node['id'];
            $path = $node['path'];
            if (!is_string($id) || $id === '' || !is_string($path) || isset($ids[$id]) || isset($paths[$path])) {
                throw new InvalidArgumentException('Duplicate or invalid node identity.');
            }
            SidecarPath::node($ast, $path, $validPaths);
            $ids[$id] = $paths[$path] = true;
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function snapshot(array $node): string
    {
        return AstPatch::fingerprint($node);
    }
}
