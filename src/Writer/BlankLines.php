<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * C2-W-021, C2-W-117, C2-W-151: a soft break of a paragraph, a heading or a line of a line block that ends a line
 * holding nothing, or only spaces and tabs, is written as an empty line or as the end of the block, so the tree that
 * reads back splits the block or loses the break. Each such soft break is reported, and so is one that only spaces and
 * tabs follow.
 */
final class BlankLines
{
    /**
     * @var array
     */
    /**
     * @var array<string, true>
     */
    private const HOLDERS = ['paragraph' => true, 'heading' => true, 'line' => true];

    private bool $blank = true;

    private ?string $pending = null;

    /**
     * @param \MarkupCarve\Carve\Writer\State $w
     */
    private function __construct(private State $w)
    {
    }

    /**
     * Reports the soft breaks of every holder under `nodes`.
     *
     * @param array<mixed> $nodes
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\State $w
     *
     * @return void
     */
    public static function lose(array $nodes, string $path, State $w): void
    {
        foreach ($nodes as $i => $node) {
            if (is_array($node) && is_string($node['type'] ?? null)) {
                self::node($node, $path . '/' . $i, $w);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\State $w
     *
     * @return void
     */
    private static function node(array $node, string $path, State $w): void
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $holder = isset(self::HOLDERS[$type]);
        if ($holder) {
            $scan = new self($w);
            $scan->inlines(is_array($node['children'] ?? null) ? $node['children'] : [], $path . '/children');
            if ($scan->blank && $scan->pending !== null) {
                $w->lose($scan->pending, 'soft_break', null);
            }
        }
        foreach ($node as $key => $value) {
            if (!is_array($value) || in_array($key, ['attrs', 'data', 'pos'], true)) {
                continue;
            }
            $at = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key);
            if (is_string($value['type'] ?? null)) {
                self::node($value, $at, $w);
            } elseif (array_is_list($value) && (!$holder || $key !== 'children')) {
                self::lose($value, $at, $w);
            }
        }
    }

    /**
     * @param array<mixed> $nodes
     * @param string $path
     *
     * @return void
     */
    private function inlines(array $nodes, string $path): void
    {
        foreach ($nodes as $i => $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? '';
            $at = $path . '/' . $i;
            if ($type === 'soft_break') {
                if ($this->blank) {
                    $this->w->lose($at, 'soft_break', null);
                }
                $this->blank = true;
                $this->pending = $at;
            } elseif ($type === 'hard_break') {
                $this->blank = true;
                $this->pending = null;
            } elseif ($type === 'text') {
                $value = is_string($node['value'] ?? null) ? $node['value'] : '';
                if (trim($value, " \t") !== '') {
                    $this->filled();
                }
            } else {
                // The markers of a container or an atom fill the line they stand on.
                $this->filled();
                if (is_array($node['children'] ?? null) && array_is_list($node['children'])) {
                    $this->inlines($node['children'], $at . '/children');
                    $this->filled();
                }
            }
        }
    }

    /**
     * @return void
     */
    private function filled(): void
    {
        $this->blank = false;
        $this->pending = null;
    }
}
