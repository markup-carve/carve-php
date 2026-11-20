<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Export;

use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Output\OutputWriter;

/**
 * The export of a 0.2 tree as text styled with ANSI escape sequences for a terminal.
 * It is also the writer of the reserved target `ansi`.
 */
final class AnsiExporter implements OutputWriter
{
    /**
     * The ANSI-styled text of `$tree` and the losses of the run.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree A tree of `Carve::parse()` or `Carve::decodeTree()`, or decoded AST JSON
     *   as `Carve::render()` takes it; it is not changed.
     * @param array<string, mixed> $options `smartTypography`, `strictLosses`, `urlPolicy`.
     *
     * @return \MarkupCarve\Carve\Export\ExportResult
     */
    public function export(Node|array $tree, array $options = []): ExportResult
    {
        $x = Exporter::start('ansi', $tree, $options);

        return $x->finish(Text::export($x, true), ($options['strictLosses'] ?? false) === true);
    }

    public function target(): string
    {
        return 'ansi';
    }

    /**
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function write(Node|array $tree, array $options = []): string
    {
        return $this->export($tree, $options)->output;
    }
}
