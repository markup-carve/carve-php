<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * The inline content of one leaf block as leaves (write-kinds.ts `KindBlock`): a text node, a break, or the markup
 * of another inline node. A leaf is `text` with `lineStart`, or a null `text` with `kind`, `dash`, `mark`, `backslash`.
 *
 * @phpstan-type Leaf array{text: \MarkupCarve\Carve\Writer\Check\Node|null, lineStart: bool, kind: string, dash: bool,
 *     mark: string|null, backslash: bool}
 */
final class KindBlock
{
    /**
     * @var list<Leaf>
     */
    public array $leaves = [];

    /**
     * @var array<int, int> The place of each text node in `leaves`, by node id.
     */
    public array $places = [];
}
