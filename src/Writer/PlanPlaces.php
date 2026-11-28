<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * The position of each inline list in a planned block group.
 * Mirrors `notePlaces` and `innerPlace` in carve-js write-plan.ts.
 *
 * @phpstan-import-type Place from \MarkupCarve\Carve\Writer\PlanLines
 */
final class PlanPlaces
{
    /**
     * @var array<int, string>
     */
    private const FLAGS = [
        'paragraph', 'inItem', 'inQuote', 'inDescriptionList', 'nested', 'followed',
        'attributed', 'documentStart', 'line', 'term', 'capped',
    ];

    /**
     * @var array<string, int>
     */
    private static array $beforeIds = [];

    /**
     * @var array<int, string>
     */
    private static array $beforeNames = [];

    /**
     * @var int
     */
    private const MAX_DEPTH = 200;

    /**
     * @var array<int, string>
     */
    private const BLOCK_CAP_CONTAINERS = [
        'block_quote', 'list', 'list_item', 'definition_list', 'definition_description', 'footnote', 'div',
        'admonition', 'directive', 'figure_group', 'line_block',
    ];

    /**
     * @var array<int, string>
     */
    private const ITEM_BREAKERS = ['block_quote', 'div', 'admonition', 'directive', 'block_extension'];

    /**
     * @param \MarkupCarve\Carve\Writer\Plan $plan
     * @param array<mixed> $nodes
     * @param bool $first
     * @param string $path
     */
    public static function note(Plan $plan, array $nodes, string $path, bool $first): void
    {
        $pending = [[$nodes, $path, 0, 0, self::top()]];
        while ($pending !== []) {
            [$list, $listPath, $depth, $containers, $place] = array_pop($pending);
            $count = count($list);
            foreach ($list as $at => $node) {
                if (!is_array($node)) {
                    continue;
                }
                $type = self::type($node);
                $shape = self::blockShape($node, $containers);
                if ($shape !== null && $plan->undecided === null) {
                    $plan->undecided = $shape;
                }
                if (self::referenceNeedsCheck($node) && $plan->undecided === null) {
                    $plan->undecided = 'reference label';
                }
                $before = $at > 0 && is_array($list[$at - 1] ?? null) ? self::type($list[$at - 1]) : null;
                $inner = self::inner($node, $place, $before, $at + 1 < $count);
                $inner['documentStart'] = $first && $depth === 0 && $at === 0;
                $below = $containers + (in_array($type, self::BLOCK_CAP_CONTAINERS, true) ? 1 : 0);
                if ($below > self::MAX_DEPTH / 2 && $type === 'image' && $plan->undecided === null) {
                    $plan->undecided = 'deep image';
                }
                $inner['capped'] = $below >= self::MAX_DEPTH;
                foreach (self::slots($node) as $slot => $children) {
                    $childPath = $listPath . '/' . $at . '/' . $slot;
                    $plan->places[$childPath] = self::pack($inner);
                    $pending[] = [$children, $childPath, $depth + 1, $below, $inner];
                }
            }
        }
    }

    /**
     * @phpstan-return Place|null
     */
    public static function get(Plan $plan, string $path): ?array
    {
        $bits = $plan->places[$path] ?? null;
        if ($bits === null) {
            return null;
        }
        $place = [];
        foreach (self::FLAGS as $at => $name) {
            $place[$name] = ($bits & (1 << $at)) !== 0;
        }
        $item = ($bits >> 11) & 3;
        $place['item'] = $item === 1 ? 'plain' : ($item === 2 ? 'task' : null);
        $before = $bits >> 13;
        $place['before'] = $before === 0 ? null : (self::$beforeNames[$before] ?? null);

        return $place;
    }

    /**
     * @phpstan-param Place $place
     */
    private static function pack(array $place): int
    {
        $bits = 0;
        foreach (self::FLAGS as $at => $name) {
            if ($place[$name]) {
                $bits |= 1 << $at;
            }
        }
        $bits |= match ($place['item']) {
            'plain' => 1 << 11,
            'task' => 2 << 11,
            default => 0,
        };
        $before = $place['before'];
        if ($before !== null) {
            $id = self::$beforeIds[$before] ?? null;
            if ($id === null) {
                $id = count(self::$beforeNames) + 1;
                self::$beforeIds[$before] = $id;
                self::$beforeNames[$id] = $before;
            }
            $bits |= $id << 13;
        }

        return $bits;
    }

    /**
     * @phpstan-return Place
     */
    private static function top(): array
    {
        return [
            'paragraph' => false,
            'inItem' => false,
            'inQuote' => false,
            'inDescriptionList' => false,
            'nested' => false,
            'item' => null,
            'before' => null,
            'followed' => false,
            'attributed' => false,
            'documentStart' => false,
            'line' => false,
            'term' => false,
            'capped' => false,
        ];
    }

    /**
     * @phpstan-param Place $place
     *
     * @phpstan-return Place
     *
     * @param array<mixed> $node
     * @param array $place
     * @param bool $followed
     * @param string|null $before
     */
    private static function inner(array $node, array $place, ?string $before, bool $followed): array
    {
        $type = self::type($node);

        return [
            'paragraph' => $type === 'paragraph',
            'inItem' => !in_array($type, self::ITEM_BREAKERS, true)
                && ($place['inItem'] || in_array($type, ['list_item', 'definition_description', 'footnote'], true)),
            'inQuote' => $place['inQuote'] || $type === 'block_quote',
            'inDescriptionList' => $place['inDescriptionList'] || $type === 'definition_list',
            'nested' => $place['nested'] || $type !== 'paragraph',
            'item' => $type === 'list_item' ? (isset($node['task']) ? 'task' : 'plain')
                : ($type === 'paragraph' ? $place['item'] : null),
            'before' => $before,
            'followed' => $followed,
            'attributed' => $type === 'paragraph' && BraceBlock::of($node) !== '',
            'documentStart' => false,
            'line' => $type === 'line',
            'term' => $type === 'definition_term',
            'capped' => false,
        ];
    }

    /**
     * @param array<mixed> $node
     * @param int $level
     */
    private static function blockShape(array $node, int $level): ?string
    {
        $type = self::type($node);
        if ($level >= self::MAX_DEPTH && in_array($type, self::BLOCK_CAP_CONTAINERS, true)) {
            return 'block depth cap';
        }
        if (
            $type === 'paragraph' && !isset($node['role']) && self::holdsOnly(
                $node['children'] ?? [],
                static fn (array $child): bool => ($child['type'] ?? '') === 'image'
                || (($child['type'] ?? '') === 'math' && ($child['display'] ?? false) === true),
            )
        ) {
            return 'block role';
        }
        $children = $node['children'] ?? null;
        if (
            $type === 'footnote' && is_array($children) && $children !== []
            && (!is_array($children[0] ?? null) || ($children[0]['type'] ?? '') !== 'paragraph')
        ) {
            return 'footnote container';
        }

        return null;
    }

    /**
     * @param mixed $children
     * @param callable(array<mixed>): bool $test
     */
    private static function holdsOnly(mixed $children, callable $test): bool
    {
        if (!is_array($children)) {
            return false;
        }
        $found = 0;
        foreach ($children as $child) {
            if (!is_array($child)) {
                return false;
            }
            if ($test($child)) {
                $found++;
            } elseif (!in_array(self::type($child), ['comment', 'soft_break', 'hard_break'], true)) {
                return false;
            }
        }

        return $found === 1;
    }

    /**
     * @param array<mixed> $node
     */
    private static function referenceNeedsCheck(array $node): bool
    {
        $type = self::type($node);
        if ($type === 'image') {
            return is_string($node['alt'] ?? null) && preg_match('/%%[^\r\n]*\[/u', $node['alt']) === 1;
        }
        if ($type !== 'link' || ($node['refForm'] ?? null) !== 'collapsed') {
            return false;
        }
        $children = $node['children'] ?? null;
        foreach (is_array($children) ? $children : [] as $child) {
            if (is_array($child) && self::type($child) === 'comment') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     */
    private static function type(array $node): string
    {
        return is_string($node['type'] ?? null) ? $node['type'] : '';
    }

    /**
     * Only node lists are positions. Attribute pairs and opaque extension data have no child position.
     *
     * @param array<mixed> $node
     *
     * @return array<string, array<mixed>>
     */
    private static function slots(array $node): array
    {
        $slots = [];
        foreach ($node as $name => $value) {
            if (
                in_array($name, ['attrs', 'metadata', 'data', 'pos', 'segments', 'props'], true)
                || !is_array($value) || !array_is_list($value)
            ) {
                continue;
            }
            if ($value !== [] && !is_array($value[0])) {
                continue;
            }
            $slots[$name] = $value;
        }

        return $slots;
    }
}
