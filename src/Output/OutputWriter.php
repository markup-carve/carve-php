<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Output;

use MarkupCarve\Carve\Node;

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
     * @param \MarkupCarve\Carve\Node $tree A tree of `Carve::parse()` or `Carve::decodeTree()`.
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function write(Node $tree, array $options = []): string;
}
