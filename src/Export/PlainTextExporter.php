<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Export;

use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Output\OutputWriter;

/**
 * The plain-text export of a 0.2 tree.
 * It is also the writer of the reserved target `plain`.
 */
final class PlainTextExporter implements OutputWriter
{
    /**
     * The plain text of `$tree` and the losses of the run.
     *
     * @param \MarkupCarve\Carve\Node $tree A tree of `Carve::parse()` or `Carve::decodeTree()`; it is not changed.
     * @param array<string, mixed> $options `smartTypography`, `strictLosses`, `urlPolicy`.
     *
     * @return \MarkupCarve\Carve\Export\ExportResult
     */
    public function export(Node $tree, array $options = []): ExportResult
    {
        $x = Exporter::start('plain', $tree, $options);

        return $x->finish(Text::export($x, false), ($options['strictLosses'] ?? false) === true);
    }

    public function target(): string
    {
        return 'plain';
    }

    /**
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function write(Node $tree, array $options = []): string
    {
        return $this->export($tree, $options)->output;
    }
}
