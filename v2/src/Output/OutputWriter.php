<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Output;

use MarkupCarve\Carve2\Node;

/**
 * A writer of one output target. It reads the resolved tree and an option map; it validates its own options and throws
 * `OptionError` for one it does not take.
 */
interface OutputWriter
{
    /**
     * The target name, unique in a registry: `html`, `carve`, and later `markdown`, `text`, `ansi`.
     *
     * @return string
     */
    public function target(): string;

    /**
     * @param \MarkupCarve\Carve2\Node $tree A tree of `Carve2::parse()` or `Carve2::decodeTree()`.
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function write(Node $tree, array $options = []): string;
}
